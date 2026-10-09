<?php

declare(strict_types=1);

namespace Stewart\Testing\Registry;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\RegistryEditException;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Contracts\Registry\RegistryEditor;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;
use Stewart\Testing\Registry\Collection\RecordedRegistryUpdateCollection;

// Without a registry every entity counts as present with an empty entry.
final class RecordingRegistryEditor implements RegistryEditor
{
    public private(set) RecordedRegistryUpdateCollection $updates;

    private ?RegistryEditException $refusal = null;

    public function __construct(private readonly ?InMemoryRegistry $registry = null)
    {
        $this->updates = RecordedRegistryUpdateCollection::empty();
    }

    public function refuseUpdates(RegistryEditException $refusal): void
    {
        $this->refusal = $refusal;
    }

    public function updateEntity(EntityId|string $entityId, EntityRegistryUpdate $update): RegisteredEntity
    {
        $entityId = EntityId::fromStringOrId($entityId);

        if ($update->isEmpty()) {
            throw RegistryEditException::nothingToUpdate($entityId);
        }

        if ($this->refusal !== null) {
            throw $this->refusal;
        }

        $updated = $update->applyTo($this->findCurrentEntry($entityId));
        $this->updates = $this->updates->withRecordedUpdate(new RecordedRegistryUpdate($entityId, $update));
        $this->registry?->seedEntity($updated);

        return $updated;
    }

    /** @throws RegistryEditException */
    private function findCurrentEntry(EntityId $entityId): RegisteredEntity
    {
        if ($this->registry === null) {
            return new RegisteredEntity($entityId);
        }

        return $this->registry->findEntity($entityId) ?? throw RegistryEditException::notFound($entityId);
    }
}
