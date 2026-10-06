<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;

#[IpcMessage(tag: 'event_fire_request')]
final readonly class EventFireRequest implements WorkerMessage
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public CorrelationId $correlationId,
        public ResourceScope $scope,
        public string $eventType,
        public array $data,
    ) {}
}
