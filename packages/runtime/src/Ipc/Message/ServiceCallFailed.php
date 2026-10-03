<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exception\ServiceCallError;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ExceptionDetails;

#[IpcMessage(tag: 'service_call_error')]
final readonly class ServiceCallFailed implements BrokerMessage
{
    public function __construct(
        public CorrelationId $correlationId,
        public string $message,
        public ExceptionDetails $details,
    ) {}

    public static function fromException(CorrelationId $correlationId, ServiceCallException $failure): self
    {
        return new self($correlationId, $failure->getMessage(), new ExceptionDetails($failure::class, $failure->reason->value, $failure->context));
    }

    public function getReason(): ServiceCallError
    {
        return ServiceCallError::tryFrom($this->details->reason) ?? ServiceCallError::Unreachable;
    }

    public function toException(): ServiceCallException
    {
        return ServiceCallException::fromWire($this->getReason(), $this->message, $this->details->context);
    }

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
