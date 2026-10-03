<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Runtime\Model\SubscriptionId;

#[IpcMessage(tag: 'subscription_ack')]
final readonly class SubscriptionAck implements BrokerMessage
{
    public function __construct(
        public SubscriptionId $subscriptionId,
        public bool $accepted,
        public ?string $reason,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
