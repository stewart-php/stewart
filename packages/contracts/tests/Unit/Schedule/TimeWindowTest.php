<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Schedule;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ScheduleError;
use Stewart\Contracts\Exception\ScheduleException;
use Stewart\Contracts\Schedule\TimeOfDay;
use Stewart\Contracts\Schedule\TimeWindow;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(TimeWindow::class)]
#[CoversClass(ScheduleException::class)]
final class TimeWindowTest extends TestCase
{
    use AssertsReason;

    /** @return iterable<string, array{string, bool}> */
    public static function provideMorningWindowMoments(): iterable
    {
        yield 'start' => ['06:00:00', true];
        yield 'inside' => ['07:30:00', true];
        yield 'last second' => ['08:59:59', true];
        yield 'end' => ['09:00:00', false];
        yield 'before start' => ['05:59:59', false];
    }

    #[DataProvider('provideMorningWindowMoments')]
    public function testIncludesStartButNotEnd(string $time, bool $expected): void
    {
        self::assertSame($expected, TimeWindow::between('06:00', '09:00')->includes($this->createMomentAt($time)));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function provideNightWindowMoments(): iterable
    {
        yield 'late evening' => ['23:15:00', true];
        yield 'midnight' => ['00:00:00', true];
        yield 'early morning' => ['05:59:59', true];
        yield 'end' => ['06:00:00', false];
        yield 'midday' => ['12:00:00', false];
        yield 'start' => ['22:00:00', true];
    }

    #[DataProvider('provideNightWindowMoments')]
    public function testCrossingWindowWrapsAroundMidnight(string $time, bool $expected): void
    {
        $window = TimeWindow::between('22:00', '06:00');

        self::assertTrue($window->crossesMidnight());
        self::assertSame($expected, $window->includes($this->createMomentAt($time)));
    }

    public function testDaytimeWindowDoesNotCrossMidnight(): void
    {
        self::assertFalse(TimeWindow::between('06:00', '09:00')->crossesMidnight());
    }

    public function testAcceptsTimeOfDayBounds(): void
    {
        $window = TimeWindow::between(TimeOfDay::fromHourMinuteSecond(6, 0), TimeOfDay::fromHourMinuteSecond(9, 0));

        self::assertTrue($window->includes($this->createMomentAt('06:30:00')));
    }

    public function testEqualStartAndEndThrows(): void
    {
        $this->assertThrowsReason(ScheduleError::TimeWindowEmpty, fn() => TimeWindow::between('06:00', '06:00:00'));
    }

    public function testMalformedBoundThrows(): void
    {
        $this->assertThrowsReason(ScheduleError::TimeOfDayInvalid, fn() => TimeWindow::between('06:00', 'noon'));
    }

    private function createMomentAt(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-03-10 ' . $time);
    }
}
