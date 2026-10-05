<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Fixtures\Sun;

use DateTimeImmutable;
use LogicException;
use Stewart\Contracts\Exception\SunException;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Sun\SunDay;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Sun\SunPosition;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\SunOffset;

final class StubSunCalendar implements SunCalendar
{
    public ?SunEvent $askedEvent = null;

    public ?SunOffset $askedOffset = null;

    public ?Instant $askedAfter = null;

    public function __construct(
        private readonly ?GeoLocation $location,
        private readonly ?Instant $nextEventTime = null,
    ) {}

    public function getLocation(): GeoLocation
    {
        return $this->location ?? throw SunException::locationUnknown();
    }

    public function findNextEvent(SunEvent $event, ?SunOffset $offset = null, ?Instant $after = null): ?Instant
    {
        $this->askedEvent = $event;
        $this->askedOffset = $offset;
        $this->askedAfter = $after;

        return $this->nextEventTime;
    }

    public function getDayOn(DateTimeImmutable $day): SunDay
    {
        throw new LogicException('Not stubbed.');
    }

    public function getPositionAt(Instant $moment): SunPosition
    {
        throw new LogicException('Not stubbed.');
    }

    public function getCurrentPosition(): SunPosition
    {
        throw new LogicException('Not stubbed.');
    }

    public function isSunUp(): bool
    {
        throw new LogicException('Not stubbed.');
    }
}
