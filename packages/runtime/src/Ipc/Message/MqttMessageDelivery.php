<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Wire\ListOf;
use Stewart\Runtime\Model\SubscriptionId;

#[IpcMessage(tag: 'mqtt_message')]
final readonly class MqttMessageDelivery implements BrokerMessage
{
    /** @param list<SubscriptionId> $deliverTo */
    public function __construct(
        public MqttMessage $message,
        #[ListOf(SubscriptionId::class)]
        public array $deliverTo,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Droppable;
    }
}
