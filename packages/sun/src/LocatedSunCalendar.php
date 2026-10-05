<?php

declare(strict_types=1);

namespace Stewart\Sun;

use DateTimeImmutable;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Sun\SunDay;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Sun\SunPosition;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\SunOffset;

final readonly class LocatedSunCalendar implements SunCalendar
{
    // Polar night can hide an event for months, but every event a location ever sees recurs within a year.
    private const int SEARCHED_DAYS = 368;

    public function __construct(
        private GeoLocation $location,
        private Clock $clock,
        private NoaaSolarCalculator $calculator,
    ) {}

    public function getLocation(): GeoLocation
    {
        return $this->location;
    }

    public function findNextEvent(SunEvent $event, ?SunOffset $offset = null, ?Instant $after = null): ?Instant
    {
        $offset ??= SunOffset::none();
        $after ??= $this->clock->getNow();
        $day = $after->minus($offset->getMagnitude())->toDateTime($this->clock->getTimeZone())->modify('-1 day');

        for ($searched = 0; $searched < self::SEARCHED_DAYS; ++$searched) {
            $eventTime = $this->calculator->findEventOn($day, $this->location, $event);

            if ($eventTime !== null && $offset->applyTo($eventTime)->isAfter($after)) {
                return $offset->applyTo($eventTime);
            }

            $day = $day->modify('+1 day');
        }

        return null;
    }

    public function getDayOn(DateTimeImmutable $day): SunDay
    {
        $sunDay = SunDay::forDate($day);

        foreach (SunEvent::cases() as $event) {
            $eventTime = $this->calculator->findEventOn($day, $this->location, $event);

            if ($eventTime !== null) {
                $sunDay = $sunDay->withEventTime($event, $eventTime);
            }
        }

        return $sunDay;
    }

    public function getPositionAt(Instant $moment): SunPosition
    {
        return $this->calculator->computePositionAt($moment, $this->location);
    }

    public function getCurrentPosition(): SunPosition
    {
        return $this->getPositionAt($this->clock->getNow());
    }

    public function isSunUp(): bool
    {
        return $this->calculator->isSunUpAt($this->clock->getNow(), $this->location);
    }
}
