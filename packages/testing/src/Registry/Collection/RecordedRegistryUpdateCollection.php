<?php

declare(strict_types=1);

namespace Stewart\Testing\Registry\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Testing\Registry\RecordedRegistryUpdate;

/** @extends ListCollection<RecordedRegistryUpdate> */
final readonly class RecordedRegistryUpdateCollection extends ListCollection
{
    /** @param iterable<RecordedRegistryUpdate> $updates */
    public static function fromUpdates(iterable $updates): self
    {
        return self::fromList($updates);
    }

    public function withRecordedUpdate(RecordedRegistryUpdate $update): self
    {
        return $this->withAppendedElement($update);
    }

    public function filterByEntityId(EntityId|string $entityId): self
    {
        $entityId = EntityId::fromStringOrId($entityId);

        return $this->filter(static fn(RecordedRegistryUpdate $update): bool => $update->entityId->equals($entityId));
    }
}
