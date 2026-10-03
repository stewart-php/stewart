<?php

declare(strict_types=1);

namespace Stewart\Contracts\Schedule;

use Stewart\Contracts\Exception\TimeException;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\MonotonicTime;

/** @internal */
final readonly class DelaySchedule implements ElapsedSchedule
{
    private function __construct(public Duration $delay) {}

    /** @throws TimeException */
    public static function after(Duration $delay): self
    {
        return new self($delay->requireAtLeastOneMillisecond('a one-off delay'));
    }

    public function findNextDueAfter(MonotonicTime $anchor, MonotonicTime $after): ?MonotonicTime
    {
        $due = $anchor->plus($this->delay);

        return $after->isBefore($due) ? $due : null;
    }

    public function countMissedBetween(MonotonicTime $due, MonotonicTime $now): int
    {
        return 0;
    }

    public function describe(): string
    {
        return 'once in ' . $this->delay->__toString();
    }

    public function isRecurring(): bool
    {
        return false;
    }
}
