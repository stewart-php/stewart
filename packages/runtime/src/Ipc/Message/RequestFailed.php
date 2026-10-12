<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ExceptionDetails;

#[IpcMessage(tag: 'request_failed')]
final readonly class RequestFailed implements BrokerMessage
{
    public function __construct(
        public CorrelationId $correlationId,
        public string $message,
        public ExceptionDetails $details,
    ) {}

    /** @param StewartException<ExceptionReason> $failure */
    public static function fromException(CorrelationId $correlationId, StewartException $failure): self
    {
        return new self($correlationId, $failure->getMessage(), ExceptionDetails::fromException($failure));
    }

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
