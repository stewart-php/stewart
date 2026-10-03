<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

final readonly class AppCounters
{
    public function __construct(
        public int $delivered = 0,
        public int $subscriptionDropped = 0,
        public int $scheduleRuns = 0,
        public int $publishes = 0,
        public int $failures = 0,
    ) {}
}
