<?php

declare(strict_types=1);

namespace Stewart\Runtime\Time;

use DateTimeImmutable;
use DateTimeZone;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\MonotonicTime;

final readonly class SystemClock implements Clock
{
    public function __construct(private DateTimeZone $timeZone) {}

    public static function inUtc(): self
    {
        return new self(new DateTimeZone('UTC'));
    }

    public function getNow(): Instant
    {
        return Instant::fromDateTime(new DateTimeImmutable('now', $this->timeZone));
    }

    public function getMonotonicTime(): MonotonicTime
    {
        // Revolt timers use hrtime(), so this matches the clock they fire on.
        return MonotonicTime::fromMicroseconds(intdiv((int) hrtime(true), 1_000));
    }

    public function getTimeZone(): DateTimeZone
    {
        return $this->timeZone;
    }
}
