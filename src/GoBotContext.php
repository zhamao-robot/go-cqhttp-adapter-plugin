<?php

namespace GocqAdapter;

use OneBot\Driver\Coroutine\Adaptive;
use OneBot\Driver\Event\WebSocket\WebSocketMessageEvent;
use OneBot\V12\Object\Action;
use OneBot\V12\Object\ActionResponse;
use ZM\Annotation\AnnotationHandler;
use ZM\Annotation\OneBot\BotAction;
use ZM\Context\BotConnectContext;
use ZM\Context\BotContext;
use ZM\Exception\OneBot12Exception;
use ZM\Plugin\OneBot\BotMap;

class GoBotContext extends BotContext
{
    use GoActionTrait;
}
