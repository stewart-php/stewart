<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Broker\AppRunningTotals;

/** @extends ListCollection<AppRunningTotals> */
final readonly class AppRunningTotalsCollection extends ListCollection
{
    /** @param iterable<AppRunningTotals> $totals */
    public static function fromTotals(iterable $totals): self
    {
        return self::fromList($totals);
    }
}
