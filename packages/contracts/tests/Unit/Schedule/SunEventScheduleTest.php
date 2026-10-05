<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ScheduleError;
use Stewart\Contracts\Exception\ScheduleException;
use Stewart\Contracts\Schedule\SunEventSchedule;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Tests\Fixtures\Sun\StubSunCalendar;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\SunOffset;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(SunEventSchedule::class)]
#[CoversClass(ScheduleException::class)]
final class SunEventScheduleTest extends TestCase
{
    use AssertsReason;

    public function testNextOccurrenceComesFromCalendar(): void
    {
        $offset = SunOffset::before(Duration::minutes(30));
        $calendar = new StubSunCalendar(self::createLocation(), Instant::fromIso('2026-10-05T15:45:22Z'));
        $after = new DateTimeImmutable('2026-10-05 12:00', new DateTimeZone('Europe/Budapest'));

        $next = SunEventSchedule::forEvent($calendar, SunEvent::Sunset, $offset)->findNextOccurrenceAfter($after);

        self::assertSame('2026-10-05T17:45:22+02:00', $next?->format(DATE_ATOM));
        self::assertSame(SunEvent::Sunset, $calendar->askedEvent);
        self::assertSame($offset, $calendar->askedOffset);
        self::assertTrue(Instant::fromDateTime($after)->equals($calendar->askedAfter ?? Instant::fromEpochMicroseconds(0)));
    }

    public function testNoEventMeansNoOccurrence(): void
    {
        $schedule = SunEventSchedule::forEvent(new StubSunCalendar(self::createLocation()), SunEvent::AstronomicalDusk);

        self::assertNull($schedule->findNextOccurrenceAfter(new DateTimeImmutable('2026-06-21')));
    }

    public function testUnknownLocationRefusesSchedule(): void
    {
        $this->assertThrowsReason(
            ScheduleError::SunLocationUnknown,
            static fn(): SunEventSchedule => SunEventSchedule::forEvent(new StubSunCalendar(null), SunEvent::Sunrise),
        );
    }

    public function testDescriptionNamesEventAndOffset(): void
    {
        $calendar = new StubSunCalendar(self::createLocation());

        self::assertSame('at sunrise', SunEventSchedule::forEvent($calendar, SunEvent::Sunrise)->describe());
        self::assertSame('30m before sunset', SunEventSchedule::forEvent($calendar, SunEvent::Sunset, SunOffset::before(Duration::minutes(30)))->describe());
        self::assertSame('1h after civil dusk', SunEventSchedule::forEvent($calendar, SunEvent::CivilDusk, SunOffset::after(Duration::hours(1)))->describe());
    }

    public function testSunScheduleRecurs(): void
    {
        self::assertTrue(SunEventSchedule::forEvent(new StubSunCalendar(self::createLocation()), SunEvent::Sunrise)->isRecurring());
    }

    private static function createLocation(): GeoLocation
    {
        return new GeoLocation(47.4979, 19.0402);
    }
}
