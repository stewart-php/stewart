<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Exposition\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Metrics\Exposition\HistogramBucket;

/** @extends ListCollection<HistogramBucket> */
final readonly class HistogramBucketCollection extends ListCollection
{
    /** @param iterable<HistogramBucket> $buckets */
    public static function fromBuckets(iterable $buckets): self
    {
        return self::fromList($buckets);
    }
}
