<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry\Collection;

use Stewart\Contracts\Collection\KeyedCollection;
use Stewart\Contracts\Registry\Floor;
use Stewart\Contracts\Registry\FloorId;

/** @extends KeyedCollection<string, Floor> */
final readonly class FloorCollection extends KeyedCollection
{
    /** @param iterable<Floor> $entries */
    public static function keyedByFloorId(iterable $entries): self
    {
        return self::keyedBy($entries, static fn(Floor $entry): string => $entry->floorId->value);
    }

    public function find(FloorId $floorId): ?Floor
    {
        return $this->elementAt($floorId->value);
    }
}
