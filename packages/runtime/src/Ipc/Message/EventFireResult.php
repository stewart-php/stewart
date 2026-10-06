<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\State\EventContext;
use Stewart\Runtime\Model\CorrelationId;

#[IpcMessage(tag: 'event_fire_result')]
final readonly class EventFireResult implements BrokerMessage
{
    public function __construct(
        public CorrelationId $correlationId,
        public EventContext $context,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
