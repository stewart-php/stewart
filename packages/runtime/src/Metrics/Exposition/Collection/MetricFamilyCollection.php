<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Exposition\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;

/** @extends ListCollection<MetricFamily> */
final readonly class MetricFamilyCollection extends ListCollection
{
    /** @param iterable<MetricFamily> $families */
    public static function fromFamilies(iterable $families): self
    {
        return self::fromList($families);
    }
}
