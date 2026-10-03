<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Schedule\CalendarSchedule;
use Stewart\Contracts\Schedule\DayOfWeek;
use Stewart\Contracts\Schedule\TimeOfDay;

#[CoversClass(CalendarSchedule::class)]
#[CoversClass(TimeOfDay::class)]
#[CoversClass(DayOfWeek::class)]
final class CalendarScheduleTest extends TestCase
{
    // Clocks go forward 02:00 → 03:00 on 2026-03-29 and back 03:00 → 02:00 on 2026-10-25.
    private const string ZONE = 'Europe/Budapest';

    public function testItFiresAtTheNextOccurrenceOfTheTime(): void
    {
        $next = CalendarSchedule::dailyAt('07:00')->findNextOccurrenceAfter($this->createLocalMoment('2026-06-01 06:00:00'));

        self::assertNotNull($next);
        self::assertSame('2026-06-01 07:00:00 +02:00', $next->format('Y-m-d H:i:s P'));
    }

    public function testTimeAlreadyPastTodayLandsTomorrow(): void
    {
        $next = CalendarSchedule::dailyAt('07:00')->findNextOccurrenceAfter($this->createLocalMoment('2026-06-01 07:00:00'));

        self::assertNotNull($next);
        self::assertSame('2026-06-02 07:00:00 +02:00', $next->format('Y-m-d H:i:s P'));
    }

    public function testOnlyTheNamedWeekdaysCount(): void
    {
        // 2026-06-01 is a Monday, so the next Friday is the 5th.
        $schedule = CalendarSchedule::dailyAt('07:00', DayOfWeek::Friday);

        $next = $schedule->findNextOccurrenceAfter($this->createLocalMoment('2026-06-01 08:00:00'));

        self::assertNotNull($next);
        self::assertSame('2026-06-05 07:00:00 +02:00', $next->format('Y-m-d H:i:s P'));
    }

    public function testWallTimeLostToDstIsSkippedThatDay(): void
    {
        $next = CalendarSchedule::dailyAt('02:30')->findNextOccurrenceAfter($this->createLocalMoment('2026-03-28 12:00:00'));

        self::assertNotNull($next);
        self::assertSame(
            '2026-03-30 02:30:00 +02:00',
            $next->format('Y-m-d H:i:s P'),
            '02:30 does not exist on 2026-03-29, so the run is skipped rather than shifted to 03:30.',
        );
    }

    public function testWallTimeThatHappensTwiceHappensOnce(): void
    {
        $next = CalendarSchedule::dailyAt('02:30')->findNextOccurrenceAfter($this->createLocalMoment('2026-10-24 12:00:00'));

        self::assertNotNull($next);
        self::assertSame(
            1_792_888_200,
            $next->getTimestamp(),
            'The earlier of the two 02:30s wins; PHP\'s own setTime() would pick the later.',
        );
        self::assertSame('2026-10-25 02:30:00 +02:00', $next->format('Y-m-d H:i:s P'));
    }

    public function testSecondPassOfAnAmbiguousHourDoesNotFireAgain(): void
    {
        // 02:15 at +01:00 is the repeat of an hour that already ran.
        $after = new DateTimeImmutable('@1792891500')->setTimezone(new DateTimeZone(self::ZONE));

        self::assertSame('2026-10-25 02:25:00 +01:00', $after->format('Y-m-d H:i:s P'));

        $next = CalendarSchedule::dailyAt('02:30')->findNextOccurrenceAfter($after);

        self::assertNotNull($next);
        self::assertSame('2026-10-26 02:30:00 +01:00', $next->format('Y-m-d H:i:s P'));
    }

    public function testSkippedWeekdayFallsToNextWeek(): void
    {
        // 2026-03-29 is a Sunday, and 02:30 does not exist on it.
        $schedule = CalendarSchedule::dailyAt('02:30', DayOfWeek::Sunday);

        $next = $schedule->findNextOccurrenceAfter($this->createLocalMoment('2026-03-23 12:00:00'));

        self::assertNotNull($next);
        self::assertSame('2026-04-05 02:30:00 +02:00', $next->format('Y-m-d H:i:s P'));
    }

    public function testGapTimeExistsInZoneWithoutDst(): void
    {
        $after = new DateTimeImmutable('2026-03-28 12:00:00', new DateTimeZone('Asia/Kolkata'));

        $next = CalendarSchedule::dailyAt('02:30')->findNextOccurrenceAfter($after);

        self::assertNotNull($next);
        self::assertSame('2026-03-29 02:30:00 +05:30', $next->format('Y-m-d H:i:s P'));
    }

    public function testDuplicateWeekdaysAreCollapsed(): void
    {
        $schedule = CalendarSchedule::dailyAt(TimeOfDay::fromHourMinuteSecond(7, 0), DayOfWeek::Friday, DayOfWeek::Friday);

        self::assertSame([DayOfWeek::Friday], $schedule->days->listValues());
    }

    public function testItDescribesItself(): void
    {
        self::assertSame('daily at 07:00:00', CalendarSchedule::dailyAt('07:00')->describe());
        self::assertTrue(CalendarSchedule::dailyAt('07:00')->isRecurring());
        self::assertSame(
            'at 07:00:00 on Monday, Friday',
            CalendarSchedule::dailyAt('07:00', DayOfWeek::Monday, DayOfWeek::Friday)->describe(),
        );
    }

    private function createLocalMoment(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new DateTimeZone(self::ZONE));
    }
}
