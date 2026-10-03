<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Worker\PendingCalls;

/** @implements BrokerMessageHandler<ServiceCallResult> */
final readonly class ServiceCallResultHandler implements BrokerMessageHandler
{
    public function __construct(private PendingCalls $pending) {}

    public function handledMessageClass(): string
    {
        return ServiceCallResult::class;
    }

    /** @param ServiceCallResult $message */
    public function handle(BrokerMessage $message): void
    {
        $this->pending->resolve($message->correlationId, $message->result);
    }
}
