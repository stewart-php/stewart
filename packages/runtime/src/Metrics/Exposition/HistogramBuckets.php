<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Exposition;

use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Control\Protocol\Status\LatencyHistogram;

final readonly class HistogramBuckets
{
    /**
     * @param list<float> $upperBounds
     * @param list<int> $cumulativeCounts
     */
    public function __construct(
        public array $upperBounds,
        public array $cumulativeCounts,
        public int $count,
        public Duration $sum,
    ) {}

    public static function fromLatencyHistogram(LatencyHistogram $latency): self
    {
        return new self(
            [...array_map(static fn(int $bound): float => Duration::milliseconds($bound)->toSeconds(), $latency->boundsMs), INF],
            [...$latency->cumulativeCounts, $latency->count],
            $latency->count,
            $latency->sum,
        );
    }
}
