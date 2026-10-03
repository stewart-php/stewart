<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Wire\ListOf;
use Stewart\Runtime\Model\SubscriptionId;

#[IpcMessage(tag: 'event_fired')]
final readonly class EventFired implements BrokerMessage
{
    /** @param list<SubscriptionId> $deliverTo */
    public function __construct(
        public HaEvent $event,
        #[ListOf(SubscriptionId::class)]
        public array $deliverTo,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Droppable;
    }
}
