<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Runtime\Model\CorrelationId;

#[IpcMessage(tag: 'exposure_acknowledged')]
final readonly class ExposureAcknowledged implements BrokerMessage
{
    public function __construct(public CorrelationId $correlationId) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
