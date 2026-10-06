<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Exception\ContainerException;
use Stewart\Runtime\Exception\ControlException;
use Stewart\Runtime\Ipc\MessageHandlerIndex;

final readonly class ControlRequestDispatcher
{
    /** @var MessageHandlerIndex<ControlRequestHandler<*>> */
    private MessageHandlerIndex $handlers;

    /**
     * @param iterable<ControlRequestHandler<*>> $handlers
     * @throws ContainerException
     */
    public function __construct(iterable $handlers)
    {
        $this->handlers = MessageHandlerIndex::fromHandlers($handlers);
    }

    /** @throws StewartException */
    public function answerRequest(ClientFrame $request): ServerFrame
    {
        $handler = $this->handlers->findHandlerFor($request) ?? throw ControlException::requestUnexpected($request::class);

        return $handler->answerRequest($request);
    }
}
