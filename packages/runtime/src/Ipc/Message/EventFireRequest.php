<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exception\EventFireError;
use Stewart\Contracts\Exception\EventFireException;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Throwable;

#[IpcMessage(tag: 'event_fire_request')]
final readonly class EventFireRequest implements WorkerMessage, RefusableHaCallRequest
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public CorrelationId $correlationId,
        public ResourceScope $scope,
        public string $eventType,
        public array $data,
    ) {}

    public function createUnreachableFailure(string $detail, ?Throwable $previous = null): EventFireException
    {
        return EventFireException::unreachable($this->eventType, $detail, $previous);
    }

    public function createOverloadedFailure(string $detail): EventFireException
    {
        return EventFireException::createForReason(EventFireError::Overloaded, eventType: $this->eventType, detail: $detail);
    }
}
