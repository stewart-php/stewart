<?php

declare(strict_types=1);

namespace Stewart\Contracts\State\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Contracts\State\StateChange;

/** @extends ListCollection<StateChange> */
final readonly class StateChangeCollection extends ListCollection
{
    /** @param iterable<StateChange> $changes */
    public static function fromChanges(iterable $changes): self
    {
        return self::fromList($changes);
    }
}
