<?php

declare(strict_types=1);

namespace Stewart\Client\Registry\Collection;

use Stewart\Client\Registry\EntityRegistryEntry;
use Stewart\Contracts\Collection\KeyedCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\Collection\RegisteredEntityCollection;
use Stewart\Contracts\Registry\RegisteredEntity;

/** @extends KeyedCollection<string, EntityRegistryEntry> */
final readonly class EntityRegistryCollection extends KeyedCollection
{
    /** @param iterable<EntityRegistryEntry> $entries */
    public static function keyedByEntityId(iterable $entries): self
    {
        return self::keyedBy($entries, static fn(EntityRegistryEntry $entry): string => $entry->entityId->value);
    }

    public function find(EntityId $entityId): ?EntityRegistryEntry
    {
        return $this->elementAt($entityId->value);
    }

    public function toRegisteredEntities(): RegisteredEntityCollection
    {
        return RegisteredEntityCollection::keyedByEntityId($this->mapToList(static fn(EntityRegistryEntry $entry): RegisteredEntity => $entry->toRegisteredEntity()));
    }
}
