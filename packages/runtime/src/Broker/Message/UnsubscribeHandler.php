<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\Unsubscribe;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<Unsubscribe> */
final readonly class UnsubscribeHandler implements WorkerMessageHandler
{
    public function __construct(private SubscriptionRegistry $registry) {}

    public function handledMessageClass(): string
    {
        return Unsubscribe::class;
    }

    /** @param Unsubscribe $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->registry->remove($message->subscriptionId, ownedBy: $handle->id);
    }
}
