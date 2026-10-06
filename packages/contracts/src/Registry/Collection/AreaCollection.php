<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry\Collection;

use Stewart\Contracts\Collection\KeyedCollection;
use Stewart\Contracts\Registry\Area;
use Stewart\Contracts\Registry\AreaId;

/** @extends KeyedCollection<string, Area> */
final readonly class AreaCollection extends KeyedCollection
{
    /** @param iterable<Area> $entries */
    public static function keyedByAreaId(iterable $entries): self
    {
        return self::keyedBy($entries, static fn(Area $entry): string => $entry->areaId->value);
    }

    public function find(AreaId $areaId): ?Area
    {
        return $this->elementAt($areaId->value);
    }
}
