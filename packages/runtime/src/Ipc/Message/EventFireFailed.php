<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exception\EventFireError;
use Stewart\Contracts\Exception\EventFireException;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ExceptionDetails;

#[IpcMessage(tag: 'event_fire_error')]
final readonly class EventFireFailed implements BrokerMessage
{
    public function __construct(
        public CorrelationId $correlationId,
        public string $message,
        public ExceptionDetails $details,
    ) {}

    public static function fromException(CorrelationId $correlationId, EventFireException $failure): self
    {
        return new self($correlationId, $failure->getMessage(), new ExceptionDetails($failure::class, $failure->reason->value, $failure->context));
    }

    public function getReason(): EventFireError
    {
        return EventFireError::tryFrom($this->details->reason) ?? EventFireError::Unreachable;
    }

    public function toException(): EventFireException
    {
        return EventFireException::fromWire($this->getReason(), $this->message, $this->details->context);
    }

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
