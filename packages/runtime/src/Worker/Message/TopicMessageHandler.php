<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\TopicMessage;
use Stewart\Runtime\Model\Collection\SubscriptionIdCollection;

/** @implements BrokerMessageHandler<TopicMessage> */
final readonly class TopicMessageHandler implements BrokerMessageHandler
{
    public function __construct(private LocalDispatcher $dispatcher) {}

    public function handledMessageClass(): string
    {
        return TopicMessage::class;
    }

    /** @param TopicMessage $message */
    public function handle(BrokerMessage $message): void
    {
        $this->dispatcher->dispatchTopic($message->event, SubscriptionIdCollection::fromIds($message->deliverTo));
    }
}
