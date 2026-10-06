<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\ServiceCallFailed;
use Stewart\Runtime\Worker\PendingRequests;

/** @implements BrokerMessageHandler<ServiceCallFailed> */
final readonly class ServiceCallFailedHandler implements BrokerMessageHandler
{
    public function __construct(private PendingRequests $pending) {}

    public function handledMessageClass(): string
    {
        return ServiceCallFailed::class;
    }

    /** @param ServiceCallFailed $message */
    public function handle(BrokerMessage $message): void
    {
        $this->pending->reject($message->correlationId, $message->toException());
    }
}
