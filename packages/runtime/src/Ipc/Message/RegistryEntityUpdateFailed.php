<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exception\RegistryEditError;
use Stewart\Contracts\Exception\RegistryEditException;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ExceptionDetails;

#[IpcMessage(tag: 'registry_entity_update_error')]
final readonly class RegistryEntityUpdateFailed implements BrokerMessage
{
    public function __construct(
        public CorrelationId $correlationId,
        public string $message,
        public ExceptionDetails $details,
    ) {}

    public static function fromException(CorrelationId $correlationId, RegistryEditException $failure): self
    {
        return new self($correlationId, $failure->getMessage(), new ExceptionDetails($failure::class, $failure->reason->value, $failure->context));
    }

    public function getReason(): RegistryEditError
    {
        return RegistryEditError::tryFrom($this->details->reason) ?? RegistryEditError::Unreachable;
    }

    public function toException(): RegistryEditException
    {
        return RegistryEditException::fromWire($this->getReason(), $this->message, $this->details->context);
    }

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
