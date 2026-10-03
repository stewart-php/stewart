<?php

declare(strict_types=1);

namespace Stewart\Contracts\Schedule;

use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;

final readonly class ScheduledRun
{
    public function __construct(
        public ScheduledTask $task,
        public Instant $scheduledFor,
        public Instant $firedAt,
        public int $missedOccurrences,
    ) {}

    public function getLateness(): Duration
    {
        return $this->firedAt->elapsedSince($this->scheduledFor);
    }
}
