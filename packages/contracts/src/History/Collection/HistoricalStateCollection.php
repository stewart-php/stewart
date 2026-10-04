<?php

declare(strict_types=1);

namespace Stewart\Contracts\History\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Contracts\State\EntityState;

/** @extends ListCollection<EntityState> */
final readonly class HistoricalStateCollection extends ListCollection
{
    /** @param iterable<EntityState> $states */
    public static function fromStates(iterable $states): self
    {
        return self::fromList($states);
    }
}
