<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Stewart\Runtime\Broker\EventRouter;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\Publish;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<Publish> */
final readonly class PublishHandler implements WorkerMessageHandler
{
    public function __construct(private EventRouter $router) {}

    public function handledMessageClass(): string
    {
        return Publish::class;
    }

    /** @param Publish $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->router->routePublish($message);
    }
}
