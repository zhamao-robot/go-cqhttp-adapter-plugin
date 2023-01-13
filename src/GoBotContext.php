<?php

namespace GocqAdapter;

use OneBot\Driver\Coroutine\Adaptive;
use OneBot\Driver\Event\WebSocket\WebSocketMessageEvent;
use OneBot\V12\Object\Action;
use OneBot\V12\Object\ActionResponse;
use ZM\Annotation\AnnotationHandler;
use ZM\Annotation\OneBot\BotAction;
use ZM\Context\BotContext;
use ZM\Exception\OneBot12Exception;

class GoBotContext extends BotContext
{
    public function sendAction(string $action, array $params = [], ?array $self = null): bool|ActionResponse
    {
        // 前面这里和 OneBot 12 的 sendAction 完全一样
        // 声明 Action 对象
        $a = new Action($action, $params, ob_uuidgen(), $self);
        // 调用事件在回复之前的回调
        $handler = new AnnotationHandler(BotAction::class);
        container()->set(Action::class, $a);
        $handler->setRuleCallback(fn (BotAction $act) => $act->action === '' || $act->action === $action && !$act->need_response);
        $handler->handleAll($a);
        // 被阻断时候，就不发送了
        if ($handler->getStatus() === AnnotationHandler::STATUS_INTERRUPTED) {
            return false;
        }

        // 从这里开始，gocq 需要做一个 12 -> 11 的转换
        $action_array = GocqActionConverter::getInstance()->convertAction12To11($a);
        // 将这个 action 提取出来需要记忆的 echo
        GocqAdapter::$action_hold_list[$a->echo] = $action_array;

        // 调用机器人连接发送 Action
        if ($this->base_event instanceof WebSocketMessageEvent) {
            $result = $this->base_event->send(json_encode($action_array));
        }
        if (!isset($result) && container()->has('ws.message.event')) {
            $result = container()->get('ws.message.event')->send(json_encode($action_array));
        }
        // 如果开启了协程，并且成功发送，那就进入协程等待，挂起等待结果返回一个 ActionResponse 对象
        if (($result ?? false) === true && ($co = Adaptive::getCoroutine()) !== null) {
            static::$coroutine_list[$a->echo] = $co->getCid();
            $response = $co->suspend();
            if ($response instanceof ActionResponse) {
                return $response;
            }
            return false;
        }
        if (isset($result)) {
            return $result;
        }
        // 到这里表明你调用时候不在 WS 或 HTTP 上下文
        throw new OneBot12Exception('No bot connection found.');
    }
}
