<?php

declare(strict_types=1);

namespace Stewart\Support\Schedule;

use DateTimeImmutable;

/** @internal */
interface WallClockSchedule extends Schedule
{
    public function findNextOccurrenceAfter(DateTimeImmutable $after): ?DateTimeImmutable;
}
