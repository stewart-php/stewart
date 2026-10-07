<?php

declare(strict_types=1);

namespace Stewart\Contracts\Time;

use DateTimeZone;
use Stewart\Contracts\Exception\ScheduleException;
use Stewart\Contracts\Schedule\TimeOfDay;

interface Clock
{
    public function getNow(): Instant;

    public function getMonotonicTime(): MonotonicTime;

    public function getTimeZone(): DateTimeZone;

    /** @throws ScheduleException */
    public function isWithin(TimeOfDay|string $start, TimeOfDay|string $end): bool;
}
