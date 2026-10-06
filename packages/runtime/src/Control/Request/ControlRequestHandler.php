<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Ipc\MessageHandler;

/**
 * @template T of ClientFrame
 * @extends MessageHandler<T>
 */
interface ControlRequestHandler extends MessageHandler
{
    /** @throws StewartException */
    public function answerRequest(ClientFrame $request): ServerFrame;
}
