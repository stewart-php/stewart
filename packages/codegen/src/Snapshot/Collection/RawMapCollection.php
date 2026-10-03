<?php

declare(strict_types=1);

namespace Stewart\Codegen\Snapshot\Collection;

use Stewart\Codegen\Snapshot\RawMap;
use Stewart\Contracts\Collection\ListCollection;

/**
 * @internal
 * @extends ListCollection<RawMap>
 */
final readonly class RawMapCollection extends ListCollection
{
    /** @param iterable<RawMap> $maps */
    public static function fromMaps(iterable $maps): self
    {
        return self::fromList($maps);
    }
}
