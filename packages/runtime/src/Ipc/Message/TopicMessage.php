<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Topic\TopicEvent;
use Stewart\Contracts\Wire\ListOf;
use Stewart\Runtime\Model\SubscriptionId;

#[IpcMessage(tag: 'topic_message')]
final readonly class TopicMessage implements BrokerMessage
{
    /** @param list<SubscriptionId> $deliverTo */
    public function __construct(
        public TopicEvent $event,
        #[ListOf(SubscriptionId::class)]
        public array $deliverTo,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Droppable;
    }
}
