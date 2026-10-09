<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exposure;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exposure\CalendarStateFormat;
use Stewart\Contracts\Schedule\TimeOfDay;

#[CoversClass(CalendarStateFormat::class)]
final class CalendarStateFormatTest extends TestCase
{
    public function testTimeRoundTrips(): void
    {
        self::assertSame('07:05:00', CalendarStateFormat::formatTime(TimeOfDay::fromHourMinuteSecond(7, 5)));
        self::assertTrue(CalendarStateFormat::parseTime('07:05:00')?->equals(TimeOfDay::fromHourMinuteSecond(7, 5)));
    }

    public function testDateIsMidnightUtc(): void
    {
        self::assertSame('2026-10-12T00:00:00+00:00', CalendarStateFormat::parseDate('2026-10-12')?->format(DATE_ATOM));
        self::assertSame('2026-10-12', CalendarStateFormat::formatDate(new DateTimeImmutable('2026-10-12 23:30:00+02:00')));
    }

    public function testDateTimeKeepsItsOffset(): void
    {
        $moment = CalendarStateFormat::parseDateTime('2026-10-09T07:15:00+02:00');

        self::assertNotNull($moment);
        self::assertSame('2026-10-09T07:15:00+02:00', CalendarStateFormat::formatDateTime($moment));
    }

    #[DataProvider('provideUnfitStates')]
    public function testUnfitStateParsesToNull(string $time, string $date, string $moment): void
    {
        self::assertNull(CalendarStateFormat::parseTime($time));
        self::assertNull(CalendarStateFormat::parseDate($date));
        self::assertNull(CalendarStateFormat::parseDateTime($moment));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function provideUnfitStates(): iterable
    {
        yield 'overflowing fields' => ['25:00:00', '2026-13-01', '2026-10-32T07:15:00+02:00'];
        yield 'short forms' => ['7:05', '2026-1-5', '2026-10-09T07:15:00'];
        yield 'other formats' => ['noon', '12/10/2026', '2026-10-09'];
    }
}
