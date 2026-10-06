<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Stewart\Runtime\Broker\EventFireProxy;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\EventFireRequest;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<EventFireRequest> */
final readonly class EventFireRequestHandler implements WorkerMessageHandler
{
    public function __construct(private EventFireProxy $eventFires) {}

    public function handledMessageClass(): string
    {
        return EventFireRequest::class;
    }

    /** @param EventFireRequest $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->eventFires->forward($handle, $message);
    }
}
