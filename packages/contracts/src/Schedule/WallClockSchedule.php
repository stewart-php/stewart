<?php

declare(strict_types=1);

namespace Stewart\Contracts\Schedule;

use DateTimeImmutable;

interface WallClockSchedule extends Schedule
{
    public function findNextOccurrenceAfter(DateTimeImmutable $after): ?DateTimeImmutable;
}
