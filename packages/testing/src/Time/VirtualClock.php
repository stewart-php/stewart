<?php

declare(strict_types=1);

namespace Stewart\Testing\Time;

use DateTimeImmutable;
use DateTimeZone;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\MonotonicTime;

final class VirtualClock implements Clock
{
    private const string DEFAULT_START = '2026-01-01 00:00:00 UTC';

    private readonly int $startMicroseconds;

    private readonly DateTimeZone $zone;

    private int $elapsedMicroseconds = 0;

    private int $skewMicroseconds = 0;

    public function __construct(?DateTimeImmutable $start = null)
    {
        $start ??= new DateTimeImmutable(self::DEFAULT_START);

        $this->startMicroseconds = Instant::fromDateTime($start)->toEpochMicroseconds();
        $this->zone = $start->getTimezone();
    }

    public function getNow(): Instant
    {
        return Instant::fromEpochMicroseconds($this->startMicroseconds + $this->elapsedMicroseconds + $this->skewMicroseconds);
    }

    public function getMonotonicTime(): MonotonicTime
    {
        return MonotonicTime::fromMicroseconds($this->elapsedMicroseconds);
    }

    public function getTimeZone(): DateTimeZone
    {
        return $this->zone;
    }

    public function getWallTime(): DateTimeImmutable
    {
        return $this->getNow()->toDateTime($this->zone);
    }

    public function moveTo(MonotonicTime $moment): void
    {
        $this->elapsedMicroseconds = max($this->elapsedMicroseconds, $moment->toMicroseconds());
    }

    public function skip(Duration $by): void
    {
        $this->skewMicroseconds += $by->toMicroseconds();
    }

    public function rewind(Duration $by): void
    {
        $this->skewMicroseconds -= $by->toMicroseconds();
    }
}
