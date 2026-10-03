<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Stewart\Contracts\Schedule\WallClockSchedule;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;

final class WallTrigger implements Trigger
{
    private const int MAX_COUNTED_MISSED_OCCURRENCES = 100;

    // Timers sleep through NTP steps and host suspends; re-reading the wall clock catches them.
    private const int WALL_CLOCK_RECHECK_SECONDS = 60;

    private readonly Instant $armedAt;

    private ?Instant $due = null;

    public function __construct(
        private readonly WallClockSchedule $schedule,
        private readonly Clock $clock,
    ) {
        $this->armedAt = $clock->getNow();
    }

    public function occursAtAll(): bool
    {
        return $this->findNextOccurrenceAfter($this->armedAt) !== null;
    }

    public function start(): bool
    {
        $this->due = $this->findNextOccurrenceAfter($this->armedAt);

        return $this->due !== null;
    }

    public function dueAt(): ?Instant
    {
        return $this->due;
    }

    public function isDue(): bool
    {
        return $this->due !== null && !$this->clock->getNow()->isBefore($this->due);
    }

    public function delayUntilDue(): ?Duration
    {
        if ($this->due === null) {
            return null;
        }

        $delay = $this->due->elapsedSince($this->clock->getNow());
        $recheckInterval = Duration::seconds(self::WALL_CLOCK_RECHECK_SECONDS);

        return $delay->isLongerThan($recheckInterval) ? $recheckInterval : $delay;
    }

    public function advance(): int
    {
        $now = $this->clock->getNow();
        $due = $this->due ?? $now;
        $this->due = $this->findNextOccurrenceAfter($now);

        $missed = 0;
        $cursor = $due;

        while ($missed < self::MAX_COUNTED_MISSED_OCCURRENCES && ($cursor = $this->findNextOccurrenceAfter($cursor)) !== null && !$cursor->isAfter($now)) {
            ++$missed;
        }

        return $missed;
    }

    public function isRecurring(): bool
    {
        return $this->schedule->isRecurring();
    }

    public function describe(): string
    {
        return $this->schedule->describe();
    }

    private function findNextOccurrenceAfter(Instant $moment): ?Instant
    {
        $next = $this->schedule->findNextOccurrenceAfter($moment->toDateTime($this->clock->getTimeZone()));

        return $next === null ? null : Instant::fromDateTime($next);
    }
}
