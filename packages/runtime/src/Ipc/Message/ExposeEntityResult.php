<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Runtime\Model\CorrelationId;

#[IpcMessage(tag: 'expose_entity_result')]
final readonly class ExposeEntityResult implements BrokerMessage
{
    public function __construct(
        public CorrelationId $correlationId,
        public ?ExposedEntitySnapshot $snapshot,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
