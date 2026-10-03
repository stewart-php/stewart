<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\MqttMessageDelivery;
use Stewart\Runtime\Model\Collection\SubscriptionIdCollection;

/** @implements BrokerMessageHandler<MqttMessageDelivery> */
final readonly class MqttMessageDeliveryHandler implements BrokerMessageHandler
{
    public function __construct(private LocalDispatcher $dispatcher) {}

    public function handledMessageClass(): string
    {
        return MqttMessageDelivery::class;
    }

    /** @param MqttMessageDelivery $message */
    public function handle(BrokerMessage $message): void
    {
        $this->dispatcher->dispatchMqttMessage($message->message, SubscriptionIdCollection::fromIds($message->deliverTo));
    }
}
