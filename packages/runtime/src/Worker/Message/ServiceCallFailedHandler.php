<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\ServiceCallFailed;
use Stewart\Runtime\Worker\PendingCalls;

/** @implements BrokerMessageHandler<ServiceCallFailed> */
final readonly class ServiceCallFailedHandler implements BrokerMessageHandler
{
    public function __construct(private PendingCalls $pending) {}

    public function handledMessageClass(): string
    {
        return ServiceCallFailed::class;
    }

    /** @param ServiceCallFailed $message */
    public function handle(BrokerMessage $message): void
    {
        $this->pending->reject($message);
    }
}
