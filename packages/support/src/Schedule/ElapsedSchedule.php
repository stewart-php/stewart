<?php

declare(strict_types=1);

namespace Stewart\Support\Schedule;

use Stewart\Contracts\Time\MonotonicTime;

/** @internal */
interface ElapsedSchedule extends Schedule
{
    public function findNextDueAfter(MonotonicTime $anchor, MonotonicTime $after): ?MonotonicTime;

    public function countMissedBetween(MonotonicTime $due, MonotonicTime $now): int;
}
