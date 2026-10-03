<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Runtime\Model\CorrelationId;

#[IpcMessage(tag: 'service_call_result')]
final readonly class ServiceCallResult implements BrokerMessage
{
    public function __construct(
        public CorrelationId $correlationId,
        public ServiceResponse $result,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
