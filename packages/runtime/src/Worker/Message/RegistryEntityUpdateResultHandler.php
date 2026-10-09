<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\RegistryEntityUpdateResult;
use Stewart\Runtime\Worker\PendingRequests;

/** @implements BrokerMessageHandler<RegistryEntityUpdateResult> */
final readonly class RegistryEntityUpdateResultHandler implements BrokerMessageHandler
{
    public function __construct(private PendingRequests $pending) {}

    public function handledMessageClass(): string
    {
        return RegistryEntityUpdateResult::class;
    }

    /** @param RegistryEntityUpdateResult $message */
    public function handle(BrokerMessage $message): void
    {
        $this->pending->resolve($message->correlationId, $message->entity);
    }
}
