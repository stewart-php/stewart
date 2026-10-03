<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Stewart\Contracts\Schedule\ElapsedSchedule;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\MonotonicTime;

final class ElapsedTrigger implements Trigger
{
    private MonotonicTime $anchor;

    private ?MonotonicTime $due = null;

    public function __construct(
        private readonly ElapsedSchedule $schedule,
        private readonly Clock $clock,
    ) {
        $this->anchor = $clock->getMonotonicTime();
    }

    public function occursAtAll(): bool
    {
        return $this->schedule->findNextDueAfter($this->anchor, $this->anchor) !== null;
    }

    public function start(): bool
    {
        // Intervals count from going live, not from being armed.
        $this->anchor = $this->clock->getMonotonicTime();
        $this->due = $this->schedule->findNextDueAfter($this->anchor, $this->anchor);

        return $this->due !== null;
    }

    public function dueAt(): ?Instant
    {
        if ($this->due === null) {
            return null;
        }

        $offset = $this->due->toMicroseconds() - $this->clock->getMonotonicTime()->toMicroseconds();

        return Instant::fromEpochMicroseconds($this->clock->getNow()->toEpochMicroseconds() + $offset);
    }

    public function isDue(): bool
    {
        return $this->due !== null && !$this->clock->getMonotonicTime()->isBefore($this->due);
    }

    public function delayUntilDue(): ?Duration
    {
        return $this->due?->elapsedSince($this->clock->getMonotonicTime());
    }

    public function advance(): int
    {
        $now = $this->clock->getMonotonicTime();
        $due = $this->due ?? $now;
        $this->due = $this->schedule->findNextDueAfter($this->anchor, $now);

        return $this->schedule->countMissedBetween($due, $now);
    }

    public function isRecurring(): bool
    {
        return $this->schedule->isRecurring();
    }

    public function describe(): string
    {
        return $this->schedule->describe();
    }
}
