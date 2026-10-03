<?php

declare(strict_types=1);

namespace Stewart\Contracts\Entity\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Contracts\Entity\EntityId;

/** @extends ListCollection<EntityId> */
final readonly class EntityIdCollection extends ListCollection
{
    /** @param iterable<EntityId> $entityIds */
    public static function fromIds(iterable $entityIds): self
    {
        return self::fromList($entityIds);
    }

    /** @return list<string> */
    public function toStrings(): array
    {
        return $this->mapToList(static fn(EntityId $entityId): string => $entityId->value);
    }
}
