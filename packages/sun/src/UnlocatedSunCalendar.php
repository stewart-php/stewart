<?php

declare(strict_types=1);

namespace Stewart\Sun;

use DateTimeImmutable;
use Stewart\Contracts\Exception\SunException;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Sun\SunDay;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Sun\SunPosition;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\SunOffset;

final readonly class UnlocatedSunCalendar implements SunCalendar
{
    public function getLocation(): GeoLocation
    {
        throw SunException::locationUnknown();
    }

    public function findNextEvent(SunEvent $event, ?SunOffset $offset = null, ?Instant $after = null): ?Instant
    {
        throw SunException::locationUnknown();
    }

    public function getDayOn(DateTimeImmutable $day): SunDay
    {
        throw SunException::locationUnknown();
    }

    public function getPositionAt(Instant $moment): SunPosition
    {
        throw SunException::locationUnknown();
    }

    public function getCurrentPosition(): SunPosition
    {
        throw SunException::locationUnknown();
    }

    public function isSunUp(): bool
    {
        throw SunException::locationUnknown();
    }
}
