<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Exposition;

final readonly class HistogramBucket
{
    public function __construct(
        public float $upperBound,
        public int $cumulativeCount,
    ) {}
}
