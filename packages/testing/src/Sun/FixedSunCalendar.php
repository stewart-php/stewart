<?php

declare(strict_types=1);

namespace Stewart\Testing\Sun;

use DateTimeImmutable;
use Stewart\Contracts\Schedule\TimeOfDay;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Sun\SunDay;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Sun\SunPosition;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\SunOffset;

// The same event times every day, so tests can say exactly when the sun rises and sets.
final class FixedSunCalendar implements SunCalendar
{
    private const float DAYTIME_ELEVATION_DEGREES = 45.0;

    private const float SOUTH_AZIMUTH_DEGREES = 180.0;

    /** @var array<string, TimeOfDay> */
    private array $timesByEvent;

    public function __construct(
        private readonly Clock $clock,
        private readonly GeoLocation $location = new GeoLocation(47.5, 19.0),
    ) {
        $this->timesByEvent = [
            SunEvent::Sunrise->value => TimeOfDay::parse('06:00'),
            SunEvent::SolarNoon->value => TimeOfDay::parse('12:00'),
            SunEvent::Sunset->value => TimeOfDay::parse('18:00'),
        ];
    }

    public function stubEventTime(SunEvent $event, TimeOfDay|string $time): self
    {
        $this->timesByEvent[$event->value] = TimeOfDay::fromTimeOrString($time);

        return $this;
    }

    public function getLocation(): GeoLocation
    {
        return $this->location;
    }

    public function findNextEvent(SunEvent $event, ?SunOffset $offset = null, ?Instant $after = null): ?Instant
    {
        $after ??= $this->clock->getNow();
        $offset ??= SunOffset::none();
        $today = $after->toDateTime($this->clock->getTimeZone())->setTime(0, 0);

        // Yesterday counts too: an offset can push its event past midnight.
        foreach (['-1 day', 'today', '+1 day', '+2 days'] as $shift) {
            $eventTime = $this->findEventTimeOn($event, $today->modify($shift));

            if ($eventTime !== null && $offset->applyTo($eventTime)->isAfter($after)) {
                return $offset->applyTo($eventTime);
            }
        }

        return null;
    }

    public function getDayOn(DateTimeImmutable $day): SunDay
    {
        $sunDay = SunDay::forDate($day);

        foreach (SunEvent::cases() as $event) {
            $eventTime = $this->findEventTimeOn($event, $day);

            if ($eventTime !== null) {
                $sunDay = $sunDay->withEventTime($event, $eventTime);
            }
        }

        return $sunDay;
    }

    public function getPositionAt(Instant $moment): SunPosition
    {
        $day = $this->getDayOn($moment->toDateTime($this->clock->getTimeZone()));
        $sunrise = $day->findEventTime(SunEvent::Sunrise);
        $sunset = $day->findEventTime(SunEvent::Sunset);
        $isUp = $sunrise !== null && $sunset !== null && !$moment->isBefore($sunrise) && $moment->isBefore($sunset);

        return new SunPosition(self::SOUTH_AZIMUTH_DEGREES, $isUp ? self::DAYTIME_ELEVATION_DEGREES : -self::DAYTIME_ELEVATION_DEGREES);
    }

    public function getCurrentPosition(): SunPosition
    {
        return $this->getPositionAt($this->clock->getNow());
    }

    public function isSunUp(): bool
    {
        return $this->getCurrentPosition()->isHigherThan(0.0);
    }

    private function findEventTimeOn(SunEvent $event, DateTimeImmutable $day): ?Instant
    {
        $time = $this->timesByEvent[$event->value] ?? null;
        $moment = $time?->resolveOnDay($day, $this->clock->getTimeZone());

        return $moment === null ? null : Instant::fromDateTime($moment);
    }
}
