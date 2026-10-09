<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\RegistryEditException;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Contracts\Registry\RegistryEditor;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Worker\Registry\RegistryEditRequester;

final readonly class WorkerRegistryEditor implements RegistryEditor
{
    public function __construct(
        private RegistryEditRequester $requester,
        private ResourceScope $resourceScope,
    ) {}

    public function forApp(AppId $appId): self
    {
        return new self($this->requester, ResourceScope::forApp($appId));
    }

    public function updateEntity(EntityId|string $entityId, EntityRegistryUpdate $update): RegisteredEntity
    {
        $entityId = EntityId::fromStringOrId($entityId);

        if ($update->isEmpty()) {
            throw RegistryEditException::nothingToUpdate($entityId);
        }

        return $this->requester->requestEntityUpdate($this->resourceScope, $entityId, $update);
    }
}
