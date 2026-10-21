<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Contracts\Time\Instant;

final class AppActivity
{
    public private(set) int $delivered = 0;

    public private(set) int $handled = 0;

    public private(set) ?Instant $lastHandledAt = null;

    public private(set) int $dropped = 0;

    public private(set) int $scheduleRuns = 0;

    public private(set) int $publishes = 0;

    public private(set) int $failures = 0;

    public private(set) int $suppressed = 0;

    public function recordDeliveredEvent(): void
    {
        ++$this->delivered;
    }

    public function recordHandledEvent(Instant $at): void
    {
        ++$this->handled;
        $this->lastHandledAt = $at;
    }

    public function recordDroppedEvent(): void
    {
        ++$this->dropped;
    }

    public function recordScheduleRun(): void
    {
        ++$this->scheduleRuns;
    }

    public function recordPublish(): void
    {
        ++$this->publishes;
    }

    public function recordFailure(): void
    {
        ++$this->failures;
    }

    public function recordSuppressedWork(): void
    {
        ++$this->suppressed;
    }

    public function isIdle(): bool
    {
        return $this->delivered === 0
            && $this->handled === 0
            && $this->dropped === 0
            && $this->scheduleRuns === 0
            && $this->publishes === 0
            && $this->failures === 0
            && $this->suppressed === 0;
    }
}
