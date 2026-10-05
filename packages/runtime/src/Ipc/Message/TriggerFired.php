<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Contracts\Wire\ListOf;
use Stewart\Runtime\Model\SubscriptionId;

#[IpcMessage(tag: 'trigger_fired')]
final readonly class TriggerFired implements BrokerMessage
{
    /** @param list<SubscriptionId> $deliverTo */
    public function __construct(
        public TriggerEvent $event,
        #[ListOf(SubscriptionId::class)]
        public array $deliverTo,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Droppable;
    }
}
