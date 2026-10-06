<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\EventFireResult;
use Stewart\Runtime\Worker\PendingRequests;

/** @implements BrokerMessageHandler<EventFireResult> */
final readonly class EventFireResultHandler implements BrokerMessageHandler
{
    public function __construct(private PendingRequests $pending) {}

    public function handledMessageClass(): string
    {
        return EventFireResult::class;
    }

    /** @param EventFireResult $message */
    public function handle(BrokerMessage $message): void
    {
        $this->pending->resolve($message->correlationId, $message->context);
    }
}
