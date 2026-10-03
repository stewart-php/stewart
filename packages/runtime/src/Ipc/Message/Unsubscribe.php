<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Runtime\Model\SubscriptionId;

#[IpcMessage(tag: 'unsubscribe')]
final readonly class Unsubscribe implements WorkerMessage
{
    public function __construct(public SubscriptionId $subscriptionId) {}
}
