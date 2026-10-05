<?php

declare(strict_types=1);

namespace Stewart\Sun\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\SunOffset;
use Stewart\Sun\LocatedSunCalendar;
use Stewart\Sun\NoaaSolarCalculator;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(LocatedSunCalendar::class)]
final class LocatedSunCalendarTest extends TestCase
{
    private const string BUDAPEST_SUNRISE = '2026-10-05T04:48:23Z';
    private const string BUDAPEST_SUNSET = '2026-10-05T16:15:22Z';
    private const string BUDAPEST_NEXT_SUNSET = '2026-10-06T16:13:23Z';

    public function testNextSunriseIsTodaysBeforeDawn(): void
    {
        $calendar = $this->createBudapestCalendarAt('2026-10-05T02:00:00Z');

        self::assertSame(self::BUDAPEST_SUNRISE, $this->formatToSecond($calendar->findNextEvent(SunEvent::Sunrise)));
    }

    public function testNextSunriseIsTomorrowsOncePassed(): void
    {
        $calendar = $this->createBudapestCalendarAt('2026-10-05T06:00:00Z');

        self::assertStringStartsWith('2026-10-06T04:4', $this->formatToSecond($calendar->findNextEvent(SunEvent::Sunrise)));
    }

    public function testEventAtExactlyAfterIsSkipped(): void
    {
        $calendar = $this->createBudapestCalendarAt('2026-10-05T02:00:00Z');

        $next = $calendar->findNextEvent(SunEvent::Sunset, after: Instant::fromIso(self::BUDAPEST_SUNSET));

        self::assertSame(self::BUDAPEST_NEXT_SUNSET, $this->formatToSecond($next));
    }

    public function testOffsetBeforeAppliesToTheEventTime(): void
    {
        $calendar = $this->createBudapestCalendarAt('2026-10-05T12:00:00Z');

        $next = $calendar->findNextEvent(SunEvent::Sunset, SunOffset::before(Duration::minutes(30)));

        self::assertSame('2026-10-05T15:45:22Z', $this->formatToSecond($next));
    }

    public function testPassedOffsetBeforeRollsToTomorrow(): void
    {
        $calendar = $this->createBudapestCalendarAt('2026-10-05T16:00:00Z');

        $next = $calendar->findNextEvent(SunEvent::Sunset, SunOffset::before(Duration::minutes(30)));

        self::assertSame('2026-10-06T15:43:23Z', $this->formatToSecond($next));
    }

    public function testOffsetAfterCrossingMidnightIsStillFound(): void
    {
        $calendar = $this->createBudapestCalendarAt('2026-10-05T23:30:00Z');

        $next = $calendar->findNextEvent(SunEvent::Sunset, SunOffset::after(Duration::hours(8)));

        self::assertSame('2026-10-06T00:15:22Z', $this->formatToSecond($next));
    }

    public function testPolarNightSunriseComesInJanuary(): void
    {
        $tromso = new GeoLocation(69.6492, 18.9553);
        $clock = new VirtualClock(new DateTimeImmutable('2026-12-01 12:00', new DateTimeZone('Europe/Oslo')));
        $calendar = new LocatedSunCalendar($tromso, $clock, new NoaaSolarCalculator());

        self::assertStringStartsWith('2027-01-', $this->formatToSecond($calendar->findNextEvent(SunEvent::Sunrise)));
    }

    public function testDayListsOnlyEventsThatOccur(): void
    {
        $tromso = new GeoLocation(69.6492, 18.9553);
        $calendar = new LocatedSunCalendar($tromso, new VirtualClock(), new NoaaSolarCalculator());

        $day = $calendar->getDayOn(new DateTimeImmutable('2026-06-21', new DateTimeZone('Europe/Oslo')));

        self::assertNull($day->findEventTime(SunEvent::Sunset));
        self::assertNotNull($day->findEventTime(SunEvent::SolarNoon));
    }

    public function testDayHoldsSunriseOfThatDate(): void
    {
        $calendar = $this->createBudapestCalendarAt('2026-01-01T00:00:00Z');

        $day = $calendar->getDayOn(new DateTimeImmutable('2026-10-05', new DateTimeZone('Europe/Budapest')));

        self::assertSame(self::BUDAPEST_SUNRISE, $this->formatToSecond($day->findEventTime(SunEvent::Sunrise)));
    }

    public function testSunIsUpAtNoonAndDownAtMidnight(): void
    {
        self::assertTrue($this->createBudapestCalendarAt('2026-10-05T10:30:00Z')->isSunUp());
        self::assertFalse($this->createBudapestCalendarAt('2026-10-05T22:30:00Z')->isSunUp());
    }

    public function testCurrentPositionFollowsClock(): void
    {
        $calendar = $this->createBudapestCalendarAt('2026-10-05T10:30:00Z');

        self::assertEquals($calendar->getPositionAt(Instant::fromIso('2026-10-05T10:30:00Z')), $calendar->getCurrentPosition());
    }

    public function testLocationIsReturned(): void
    {
        self::assertSame(47.4979, $this->createBudapestCalendarAt('2026-10-05T10:30:00Z')->getLocation()->latitude);
    }

    private function createBudapestCalendarAt(string $now): LocatedSunCalendar
    {
        $clock = new VirtualClock(Instant::fromIso($now)->toDateTime(new DateTimeZone('Europe/Budapest')));

        return new LocatedSunCalendar(new GeoLocation(47.4979, 19.0402), $clock, new NoaaSolarCalculator());
    }

    private function formatToSecond(?Instant $instant): string
    {
        self::assertNotNull($instant);

        return $instant->toDateTime(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
