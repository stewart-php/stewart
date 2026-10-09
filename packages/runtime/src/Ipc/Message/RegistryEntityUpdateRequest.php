<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;

#[IpcMessage(tag: 'registry_entity_update_request')]
final readonly class RegistryEntityUpdateRequest implements WorkerMessage
{
    public function __construct(
        public CorrelationId $correlationId,
        public ResourceScope $scope,
        public EntityId $entityId,
        public EntityRegistryUpdate $update,
    ) {}
}
