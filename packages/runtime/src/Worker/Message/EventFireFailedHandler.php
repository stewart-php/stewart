<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\EventFireFailed;
use Stewart\Runtime\Worker\PendingRequests;

/** @implements BrokerMessageHandler<EventFireFailed> */
final readonly class EventFireFailedHandler implements BrokerMessageHandler
{
    public function __construct(private PendingRequests $pending) {}

    public function handledMessageClass(): string
    {
        return EventFireFailed::class;
    }

    /** @param EventFireFailed $message */
    public function handle(BrokerMessage $message): void
    {
        $this->pending->reject($message->correlationId, $message->toException());
    }
}
