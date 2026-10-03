<?php

declare(strict_types=1);

namespace Stewart\Contracts\Time;

use DateTimeZone;

interface Clock
{
    public function getNow(): Instant;

    public function getMonotonicTime(): MonotonicTime;

    public function getTimeZone(): DateTimeZone;
}
