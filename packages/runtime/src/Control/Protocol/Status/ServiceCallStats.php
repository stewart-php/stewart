<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Runtime\Model\ServiceCallOutcome;

final readonly class ServiceCallStats
{
    public function __construct(
        public ServiceCallOutcome $outcome,
        public int $count,
        public ?LatencyHistogram $latency,
    ) {}
}
