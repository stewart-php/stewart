<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Unit\Sun;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\SunOffset;
use Stewart\Testing\Sun\FixedSunCalendar;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(FixedSunCalendar::class)]
final class FixedSunCalendarTest extends TestCase
{
    private VirtualClock $clock;

    private FixedSunCalendar $calendar;

    protected function setUp(): void
    {
        $this->clock = new VirtualClock(new DateTimeImmutable('2026-06-01 10:00:00 UTC'));
        $this->calendar = new FixedSunCalendar($this->clock);
    }

    public function testNextEventIsLaterTodayOrTomorrow(): void
    {
        self::assertSame('2026-06-01 18:00', self::format($this->calendar->findNextEvent(SunEvent::Sunset)));
        self::assertSame('2026-06-02 06:00', self::format($this->calendar->findNextEvent(SunEvent::Sunrise)));
    }

    public function testOffsetCanCrossMidnight(): void
    {
        $this->calendar->stubEventTime(SunEvent::Sunset, '23:30');

        self::assertSame('2026-06-02 00:15', self::format($this->calendar->findNextEvent(SunEvent::Sunset, SunOffset::after(Duration::minutes(45)))));
    }

    public function testEventWithoutATimeNeverOccurs(): void
    {
        self::assertNull($this->calendar->findNextEvent(SunEvent::CivilDusk));
        self::assertNull($this->calendar->getDayOn(new DateTimeImmutable('2026-06-01'))->findEventTime(SunEvent::CivilDusk));
    }

    public function testSunIsUpBetweenSunriseAndSunset(): void
    {
        self::assertTrue($this->calendar->isSunUp());

        $this->clock->skip(Duration::hours(9));

        self::assertFalse($this->calendar->isSunUp());
    }

    private static function format(?Instant $moment): ?string
    {
        return $moment?->toDateTime(new DateTimeZone('UTC'))->format('Y-m-d H:i');
    }
}
