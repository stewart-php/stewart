<?php

declare(strict_types=1);

namespace Stewart\Contracts\Trigger\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Contracts\Trigger\HaTrigger;

/** @extends ListCollection<HaTrigger> */
final readonly class HaTriggerCollection extends ListCollection
{
    /** @param iterable<HaTrigger> $triggers */
    public static function fromTriggers(iterable $triggers): self
    {
        return self::fromList($triggers);
    }
}
