<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\RegistryEntityUpdateFailed;
use Stewart\Runtime\Worker\PendingRequests;

/** @implements BrokerMessageHandler<RegistryEntityUpdateFailed> */
final readonly class RegistryEntityUpdateFailedHandler implements BrokerMessageHandler
{
    public function __construct(private PendingRequests $pending) {}

    public function handledMessageClass(): string
    {
        return RegistryEntityUpdateFailed::class;
    }

    /** @param RegistryEntityUpdateFailed $message */
    public function handle(BrokerMessage $message): void
    {
        $this->pending->reject($message->correlationId, $message->toException());
    }
}
