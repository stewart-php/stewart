<?php

declare(strict_types=1);

namespace Stewart\Contracts\Sun;

use DateTimeImmutable;
use Stewart\Contracts\Exception\SunException;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\SunOffset;

interface SunCalendar
{
    /** @throws SunException */
    public function getLocation(): GeoLocation;

    /** @throws SunException */
    public function findNextEvent(SunEvent $event, ?SunOffset $offset = null, ?Instant $after = null): ?Instant;

    /** @throws SunException */
    public function getDayOn(DateTimeImmutable $day): SunDay;

    /** @throws SunException */
    public function getPositionAt(Instant $moment): SunPosition;

    /** @throws SunException */
    public function getCurrentPosition(): SunPosition;

    /** @throws SunException */
    public function isSunUp(): bool;
}
