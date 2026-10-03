<?php

declare(strict_types=1);

namespace Stewart\Contracts\Schedule;

use Stewart\Contracts\Exception\TimeException;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\MonotonicTime;

/** @internal */
final readonly class IntervalSchedule implements ElapsedSchedule
{
    private function __construct(public Duration $period) {}

    /** @throws TimeException */
    public static function every(Duration $period): self
    {
        return new self($period->requireAtLeastOneMillisecond('an interval schedule'));
    }

    public function findNextDueAfter(MonotonicTime $anchor, MonotonicTime $after): MonotonicTime
    {
        $first = $anchor->plus($this->period);

        if ($after->isBefore($first)) {
            return $first;
        }

        $period = $this->period->toMicroseconds();
        $elapsed = $after->toMicroseconds() - $first->toMicroseconds();

        return MonotonicTime::fromMicroseconds($first->toMicroseconds() + (intdiv($elapsed, $period) + 1) * $period);
    }

    public function countMissedBetween(MonotonicTime $due, MonotonicTime $now): int
    {
        if (!$now->isAfter($due)) {
            return 0;
        }

        return intdiv($now->toMicroseconds() - $due->toMicroseconds(), $this->period->toMicroseconds());
    }

    public function describe(): string
    {
        return 'every ' . $this->period->__toString();
    }

    public function isRecurring(): bool
    {
        return true;
    }
}
