<?php

declare(strict_types=1);

namespace GocqAdapter;

use OneBot\Driver\Event\WebSocket\WebSocketCloseEvent;
use OneBot\Driver\Event\WebSocket\WebSocketMessageEvent;
use OneBot\Driver\Event\WebSocket\WebSocketOpenEvent;
use OneBot\V12\Exception\OneBotException;
use OneBot\V12\Object\MessageSegment;
use OneBot\V12\Object\OneBotEvent;
use ZM\Annotation\AnnotationHandler;
use ZM\Annotation\Framework\BindEvent;
use ZM\Annotation\Framework\Init;
use ZM\Annotation\OneBot\BotActionResponse;
use ZM\Annotation\OneBot\BotEvent;
use ZM\Annotation\OneBot\CommandArgument;
use ZM\Container\ContainerRegistrant;
use ZM\Context\BotContext;
use ZM\Exception\WaitTimeoutException;
use ZM\Utils\ConnectionUtil;

class GocqAdapter
{
    /**
     * @var array<string, array>
     * @internal
     */
    public static array $action_hold_list = [];

    /** @var GocqEventConverter[] */
    private static array $converters = [];

    #[Init]
    public function init(): void
    {
        logger()->info('go-cqhttp 转换器已加载！');
    }

    /**
     * [CALLBACK] 接入和认证 go-cqhttp 的反向 WebSocket 连接
     * @throws \JsonException
     */
    #[BindEvent(WebSocketOpenEvent::class)]
    public function handleWSReverseOpen(WebSocketOpenEvent $event): void
    {
        logger()->info('连接到 ob11');
        $request = $event->getRequest();
        ob_dump($request);
        // 判断是不是 Gocq 或 OneBot 11 标准的连接。OB11 标准必须带有 X-Client-Role 和 X-Self-ID 两个头。
        if ($request->getHeaderLine('X-Client-Role') === 'Universal' && $request->getHeaderLine('X-Self-ID') !== '') {
            logger()->info('检测到 OneBot 11 反向 WS 连接 ' . $request->getHeaderLine('User-Agent'));
            $info = ['gocq_impl' => 'go-cqhttp', 'self_id' => $request->getHeaderLine('X-Self-ID')];
            // TODO: 验证 Token
            ConnectionUtil::setConnection($event->getFd(), $info);
            logger()->info('已接入 go-cqhttp 的反向 WS 连接，连接 ID 为 ' . $event->getFd());
        }
    }

    /**
     * @throws OneBotException
     */
    #[BindEvent(WebSocketMessageEvent::class)]
    public function handleWSReverseMessage(WebSocketMessageEvent $event): void
    {
        // 忽略非 gocq 的消息
        $impl = ConnectionUtil::getConnection($event->getFd())['gocq_impl'] ?? null;
        if ($impl === null) {
            return;
        }

        // 解析 Frame 到 UTF-8 JSON
        $body = $event->getFrame()->getData();
        $body = json_decode($body, true);
        if ($body === null) {
            logger()->warning('收到非 JSON 格式的消息，已忽略');
            return;
        }

        if (isset($body['post_type'], $body['self_id'])) {
            $ob12 = self::getConverter($event->getFd(), strval($body['self_id']))->convertEvent($body);
            if ($ob12 === null) {
                logger()->debug('收到了不支持的 Event，丢弃此事件');
                logger()->debug('事件详情对象：' . json_encode($ob12, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return;
            }
            try {
                $obj = new OneBotEvent($ob12);
            } catch (OneBotException $e) {
                logger()->debug('收到非 OneBot 12（由11转换而来）标准的消息，已忽略');
                logger()->debug($e->getMessage());
                return;
            }

            // 绑定容器
            ContainerRegistrant::registerOBEventServices($obj, GoBotContext::class);

            // 调用 BotEvent 事件
            $handler = new AnnotationHandler(BotEvent::class);
            $handler->setRuleCallback(function (BotEvent $event) use ($obj) {
                return ($event->type === null || $event->type === $obj->type)
                    && ($event->sub_type === null || $event->sub_type === $obj->sub_type)
                    && ($event->detail_type === null || $event->detail_type === $obj->detail_type);
            });
            try {
                $handler->handleAll($obj);
            } catch (WaitTimeoutException $e) {
                // 这里是处理 prompt() 下超时的情况的
                if ($e->getTimeoutPrompt() === null) {
                    return;
                }
                if (($e->getPromptOption() & ZM_PROMPT_TIMEOUT_MENTION_USER) === ZM_PROMPT_TIMEOUT_MENTION_USER && ($ev = $e->getUserEvent()) !== null) {
                    $prompt = [MessageSegment::mention($ev->getUserId()), ...$e->getTimeoutPrompt()];
                }
                if (($e->getPromptOption() & ZM_PROMPT_TIMEOUT_QUOTE_SELF) === ZM_PROMPT_TIMEOUT_QUOTE_SELF && ($rsp = $e->getPromptResponse()) !== null && ($ev = $e->getUserEvent()) !== null) {
                    $prompt = [MessageSegment::reply($rsp->data['message_id'], $ev->self['user_id']), ...$e->getTimeoutPrompt()];
                } elseif (($e->getPromptOption() & ZM_PROMPT_TIMEOUT_QUOTE_USER) === ZM_PROMPT_TIMEOUT_QUOTE_USER && ($ev = $e->getUserEvent()) !== null) {
                    $prompt = [MessageSegment::reply($ev->getMessageId(), $ev->getUserId()), ...$e->getTimeoutPrompt()];
                }
                bot()->reply($prompt ?? $e->getTimeoutPrompt());
            }
        } elseif (isset($body['status'], $body['retcode'], $body['echo'])) {
            if (isset(self::$action_hold_list[$body['echo']])) {
                $origin_action = self::$action_hold_list[$body['echo']];
                unset(self::$action_hold_list[$body['echo']]);
                $resp = GocqActionConverter::getInstance()->convertActionResponse11To12($body, $origin_action);
                ContainerRegistrant::registerOBActionResponseServices($resp);

                // 调用 BotActionResponse 事件
                $handler = new AnnotationHandler(BotActionResponse::class);
                $handler->setRuleCallback(function (BotActionResponse $event) use ($resp) {
                    return $event->retcode === null || $event->retcode === $resp->retcode;
                });
                $handler->handleAll($resp);

                // 如果有协程，并且该 echo 记录在案的话，就恢复协程
                BotContext::tryResume($resp);
            }
        }
    }

    #[BindEvent(WebSocketCloseEvent::class)]
    public function handleWSReverseClose(WebSocketCloseEvent $event): void
    {
        unset(self::$converters[$event->getFd()]);
    }

    public static function getConverter(int $fd, ?string $self_id = null): GocqEventConverter
    {
        if (!isset(self::$converters[$fd])) {
            self::$converters[$fd] = new GocqEventConverter($self_id, 'unknown');
        }
        return self::$converters[$fd];
    }
}
