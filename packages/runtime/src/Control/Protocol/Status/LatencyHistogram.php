<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Wire\ListOf;

final readonly class LatencyHistogram
{
    /**
     * @param list<int> $boundsMs
     * @param list<int> $cumulativeCounts
     */
    public function __construct(
        #[ListOf('int')]
        public array $boundsMs,
        #[ListOf('int')]
        public array $cumulativeCounts,
        public int $count,
        public Duration $sum,
    ) {}

    public function quantileBoundMs(float $quantile): ?int
    {
        if ($this->count === 0) {
            return null;
        }

        $rank = $quantile * $this->count;

        foreach ($this->boundsMs as $index => $bound) {
            if ($this->cumulativeCounts[$index] >= $rank) {
                return $bound;
            }
        }

        return null;
    }
}
