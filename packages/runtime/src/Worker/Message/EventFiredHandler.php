<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\EventFired;
use Stewart\Runtime\Model\Collection\SubscriptionIdCollection;

/** @implements BrokerMessageHandler<EventFired> */
final readonly class EventFiredHandler implements BrokerMessageHandler
{
    public function __construct(private LocalDispatcher $dispatcher) {}

    public function handledMessageClass(): string
    {
        return EventFired::class;
    }

    /** @param EventFired $message */
    public function handle(BrokerMessage $message): void
    {
        $this->dispatcher->dispatchEvent($message->event, SubscriptionIdCollection::fromIds($message->deliverTo));
    }
}
