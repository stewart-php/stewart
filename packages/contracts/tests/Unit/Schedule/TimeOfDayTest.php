<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ScheduleError;
use Stewart\Contracts\Exception\ScheduleException;
use Stewart\Contracts\Schedule\TimeOfDay;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(TimeOfDay::class)]
#[CoversClass(ScheduleException::class)]
final class TimeOfDayTest extends TestCase
{
    use AssertsReason;

    public function testItParsesHoursAndMinutes(): void
    {
        $time = TimeOfDay::parse('07:00');

        self::assertSame(7, $time->hour);
        self::assertSame(0, $time->minute);
        self::assertSame(0, $time->second);
        self::assertSame('07:00:00', $time->format());
    }

    public function testItParsesSeconds(): void
    {
        self::assertSame('07:05:30', TimeOfDay::parse('07:05:30')->format());
    }

    public function testSingleDigitHourIsAccepted(): void
    {
        self::assertSame('07:00:00', TimeOfDay::parse('7:00')->format());
    }

    /** @return iterable<string, array{string}> */
    public static function provideMalformedTimesOfDay(): iterable
    {
        yield 'no minutes' => ['07'];
        yield 'single digit minutes' => ['07:0'];
        yield 'not a time' => ['morning'];
        yield 'empty' => [''];
        yield 'trailing text' => ['07:00 sharp'];
        yield 'trailing newline' => ["07:00\n"];
    }

    #[DataProvider('provideMalformedTimesOfDay')]
    public function testItRejectsAMalformedTime(string $value): void
    {
        $this->assertThrowsReason(ScheduleError::TimeOfDayInvalid, fn() => TimeOfDay::parse($value));
    }

    public function testItRejectsAnHourThatDoesNotExist(): void
    {
        $this->assertThrowsReason(ScheduleError::TimeOfDayOutOfRange, fn() => TimeOfDay::parse('25:00'));
    }

    public function testItRejectsAMinuteThatDoesNotExist(): void
    {
        $this->assertThrowsReason(ScheduleError::TimeOfDayOutOfRange, fn() => TimeOfDay::fromHourMinuteSecond(7, 60));
    }

    public function testItReadsTheTimeOffADateTime(): void
    {
        $moment = new DateTimeImmutable('2026-06-01 07:05:30', new DateTimeZone('Europe/Budapest'));

        self::assertSame('07:05:30', TimeOfDay::fromDateTime($moment)->format());
    }

    public function testFromPassesAValueObjectThrough(): void
    {
        $time = TimeOfDay::fromHourMinuteSecond(7, 0);

        self::assertSame($time, TimeOfDay::fromTimeOrString($time));
    }

    public function testFromParsesAString(): void
    {
        self::assertTrue(TimeOfDay::fromTimeOrString('07:00')->equals(TimeOfDay::fromHourMinuteSecond(7, 0)));
    }

    public function testEqualityComparesEveryComponent(): void
    {
        self::assertFalse(TimeOfDay::fromHourMinuteSecond(7, 0)->equals(TimeOfDay::fromHourMinuteSecond(7, 0, 1)));
    }

    public function testSecondsOfDayCountsFromMidnight(): void
    {
        self::assertSame(0, TimeOfDay::parse('00:00')->toSecondsOfDay());
        self::assertSame(86_399, TimeOfDay::parse('23:59:59')->toSecondsOfDay());
    }
}
