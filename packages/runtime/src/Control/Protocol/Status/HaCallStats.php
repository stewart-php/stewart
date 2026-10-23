<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Runtime\Model\HaCallOutcome;

final readonly class HaCallStats
{
    public function __construct(
        public HaCallOutcome $outcome,
        public int $count,
        public ?LatencyHistogram $latency,
    ) {}
}
