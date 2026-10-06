<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry\Collection;

use Stewart\Contracts\Collection\KeyedCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\RegisteredEntity;

/** @extends KeyedCollection<string, RegisteredEntity> */
final readonly class RegisteredEntityCollection extends KeyedCollection
{
    /** @param iterable<RegisteredEntity> $entries */
    public static function keyedByEntityId(iterable $entries): self
    {
        return self::keyedBy($entries, static fn(RegisteredEntity $entry): string => $entry->entityId->value);
    }

    public function find(EntityId $entityId): ?RegisteredEntity
    {
        return $this->elementAt($entityId->value);
    }
}
