<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exception\ExposureError;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ExceptionDetails;

#[IpcMessage(tag: 'exposure_error')]
final readonly class ExposureFailed implements BrokerMessage
{
    public function __construct(
        public CorrelationId $correlationId,
        public string $message,
        public ExceptionDetails $details,
    ) {}

    public static function fromException(CorrelationId $correlationId, ExposureException $failure): self
    {
        return new self($correlationId, $failure->getMessage(), new ExceptionDetails($failure::class, $failure->reason->value, $failure->context));
    }

    public function getReason(): ExposureError
    {
        return ExposureError::tryFrom($this->details->reason) ?? ExposureError::Unreachable;
    }

    public function toException(): ExposureException
    {
        return ExposureException::fromWire($this->getReason(), $this->message, $this->details->context);
    }

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
