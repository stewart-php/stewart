<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Schedule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\TimeError;
use Stewart\Contracts\Schedule\DelaySchedule;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\MonotonicTime;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(DelaySchedule::class)]
final class DelayScheduleTest extends TestCase
{
    use AssertsReason;

    private const int ANCHOR = 1_000_000;

    public function testItOccursOnceAndThenNeverAgain(): void
    {
        $schedule = DelaySchedule::after(Duration::seconds(30));

        self::assertEquals(self::createMonotonicTimeAt(self::ANCHOR + 30_000), $schedule->findNextDueAfter(self::createMonotonicTimeAt(self::ANCHOR), self::createMonotonicTimeAt(self::ANCHOR)));
        self::assertNull($schedule->findNextDueAfter(self::createMonotonicTimeAt(self::ANCHOR), self::createMonotonicTimeAt(self::ANCHOR + 30_000)));
    }

    public function testItNeverMissesAnything(): void
    {
        self::assertSame(0, DelaySchedule::after(Duration::seconds(10))->countMissedBetween(self::createMonotonicTimeAt(10_000), self::createMonotonicTimeAt(99_000)));
    }

    public function testDelayShorterThanAMillisecondIsRejected(): void
    {
        $this->assertThrowsReason(TimeError::DurationNotPositive, fn() => DelaySchedule::after(Duration::zero()));
    }

    public function testItDoesNotRecurAndDescribesItself(): void
    {
        $schedule = DelaySchedule::after(Duration::minutes(20));

        self::assertFalse($schedule->isRecurring());
        self::assertSame('once in 20m', $schedule->describe());
    }

    private static function createMonotonicTimeAt(int $milliseconds): MonotonicTime
    {
        return MonotonicTime::fromMicroseconds($milliseconds * 1_000);
    }
}
