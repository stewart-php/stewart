<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exception\HistoryError;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ExceptionDetails;

#[IpcMessage(tag: 'history_error')]
final readonly class HistoryFailed implements BrokerMessage
{
    public function __construct(
        public CorrelationId $correlationId,
        public string $message,
        public ExceptionDetails $details,
    ) {}

    public static function fromException(CorrelationId $correlationId, HistoryException $failure): self
    {
        return new self($correlationId, $failure->getMessage(), new ExceptionDetails($failure::class, $failure->reason->value, $failure->context));
    }

    public function getReason(): HistoryError
    {
        return HistoryError::tryFrom($this->details->reason) ?? HistoryError::Unreachable;
    }

    public function toException(): HistoryException
    {
        return HistoryException::fromWire($this->getReason(), $this->message, $this->details->context);
    }

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
