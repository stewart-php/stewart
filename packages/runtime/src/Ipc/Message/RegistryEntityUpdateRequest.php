<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\RegistryEditError;
use Stewart\Contracts\Exception\RegistryEditException;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Throwable;

#[IpcMessage(tag: 'registry_entity_update_request')]
final readonly class RegistryEntityUpdateRequest implements WorkerMessage, RefusableHaCallRequest
{
    public function __construct(
        public CorrelationId $correlationId,
        public ResourceScope $scope,
        public EntityId $entityId,
        public EntityRegistryUpdate $update,
    ) {}

    public function createUnreachableFailure(string $detail, ?Throwable $previous = null): RegistryEditException
    {
        return RegistryEditException::unreachable($this->entityId, $detail, $previous);
    }

    public function createOverloadedFailure(string $detail): RegistryEditException
    {
        return RegistryEditException::createForReason(RegistryEditError::Overloaded, entityId: $this->entityId->value, detail: $detail);
    }
}
