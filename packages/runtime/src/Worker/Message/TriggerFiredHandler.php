<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\TriggerFired;
use Stewart\Runtime\Model\Collection\SubscriptionIdCollection;

/** @implements BrokerMessageHandler<TriggerFired> */
final readonly class TriggerFiredHandler implements BrokerMessageHandler
{
    public function __construct(private LocalDispatcher $dispatcher) {}

    public function handledMessageClass(): string
    {
        return TriggerFired::class;
    }

    /** @param TriggerFired $message */
    public function handle(BrokerMessage $message): void
    {
        $this->dispatcher->dispatchTrigger($message->event, SubscriptionIdCollection::fromIds($message->deliverTo));
    }
}
