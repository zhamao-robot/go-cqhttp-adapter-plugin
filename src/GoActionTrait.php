<?php

namespace GocqAdapter;

use OneBot\Driver\Coroutine\Adaptive;
use OneBot\V12\Object\Action;
use OneBot\V12\Object\ActionResponse;
use ZM\Annotation\AnnotationHandler;
use ZM\Annotation\OneBot\BotAction;
use ZM\Exception\OneBot12Exception;
use ZM\Plugin\OneBot\BotMap;

trait GoActionTrait
{
    /**
     * 发送机器人动作
     *
     * @throws \Throwable
     */
    public function sendAction(string $action, array $params = [], ?array $self = null): bool|ActionResponse
    {
        if ($self === null && $this->self !== null) {
            $self = $this->self;
        }
        // 声明 Action 对象
        $a = new Action($action, $params, ob_uuidgen(), $self);
        // 调用事件在回复之前的回调
        $handler = new AnnotationHandler(BotAction::class);
        container()->set(Action::class, $a);
        $handler->setRuleCallback(fn (BotAction $act) => ($act->action === '' || $act->action === $action) && !$act->need_response);
        $handler->handleAll();
        // 被阻断时候，就不发送了
        if ($handler->getStatus() === AnnotationHandler::STATUS_INTERRUPTED) {
            return false;
        }

        // 从这里开始，gocq 需要做一个 12 -> 11 的转换
        $action_array = GocqActionConverter::getInstance()->convertAction12To11($a);
        // 将这个 action 提取出来需要记忆的 echo
        GocqAdapter::$action_hold_list[$a->echo] = $action_array;
        // 获取机器人的 BotMap 对应连接（前提是当前上下文有 self）
        if ($self !== null) {
            $fd_map = BotMap::getBotFd($self['user_id'], $self['platform']);
            if ($fd_map === null) {
                logger()->error("机器人 [{$self['platform']}:{$self['user_id']}] 没有连接或未就绪，无法发送数据");
                return false;
            }
            $result = ws_socket($fd_map[0])->send(json_encode($action_array), $fd_map[1]);
        } elseif ($this instanceof GoBotConnectContext) {
            // self 为空，说明可能是发送的元动作，需要通过 fd 来查找对应的 connect 连接
            $flag = $this->getFlag();
            $fd = $this->getFd();
            $result = ws_socket($flag)->send(json_encode($action_array), $fd);
        } elseif (method_exists($this, 'emitSendAction')) {
            $result = $this->emitSendAction($a);
        } else {
            logger()->error('未匹配到任何机器人连接');
            return false;
        }

        // 如果开启了协程，并且成功发送，那就进入协程等待，挂起等待结果返回一个 ActionResponse 对象
        if (($result ?? false) === true && ($co = Adaptive::getCoroutine()) !== null) {
            BotMap::$bot_coroutines[$a->echo] = $co->getCid();
            $response = $co->suspend();
            if ($response instanceof ActionResponse) {
                $handler = new AnnotationHandler(BotAction::class);
                $handler->setRuleCallback(fn(BotAction $act) => ($act->action === '' || $act->action === $action) && $act->need_response);
                container()->set(ActionResponse::class, $response);
                $handler->handleAll();
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
