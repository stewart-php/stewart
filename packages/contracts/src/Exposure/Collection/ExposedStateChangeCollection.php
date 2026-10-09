<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Contracts\Exposure\ExposedStateChange;

/** @extends ListCollection<ExposedStateChange> */
final readonly class ExposedStateChangeCollection extends ListCollection
{
    /** @param iterable<ExposedStateChange> $changes */
    public static function fromChanges(iterable $changes): self
    {
        return self::fromList($changes);
    }

    public function withChange(ExposedStateChange $change): self
    {
        return $this->withAppendedElement($change);
    }
}
