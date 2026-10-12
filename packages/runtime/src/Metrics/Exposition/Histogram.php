<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Exposition;

use LogicException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Control\Protocol\Status\LatencyHistogram;

final readonly class Histogram
{
    /** @param list<HistogramBucket> $buckets */
    public function __construct(
        public array $buckets,
        public int $count,
        public Duration $sum,
    ) {}

    public static function fromLatencyHistogram(LatencyHistogram $latency): self
    {
        if (\count($latency->boundsMs) !== \count($latency->cumulativeCounts)) {
            throw new LogicException(\sprintf('A latency histogram has %d bounds but %d counts.', \count($latency->boundsMs), \count($latency->cumulativeCounts)));
        }

        $buckets = [];

        foreach ($latency->boundsMs as $index => $bound) {
            $buckets[] = new HistogramBucket(Duration::milliseconds($bound)->toSeconds(), $latency->cumulativeCounts[$index]);
        }

        $buckets[] = new HistogramBucket(INF, $latency->count);

        return new self($buckets, $latency->count, $latency->sum);
    }
}
