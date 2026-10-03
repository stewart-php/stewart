<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Schedule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\TimeError;
use Stewart\Contracts\Schedule\IntervalSchedule;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\MonotonicTime;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(IntervalSchedule::class)]
final class IntervalScheduleTest extends TestCase
{
    use AssertsReason;

    private const int ANCHOR = 1_000_000;

    public function testFirstOccurrenceIsOnePeriodAfterTheAnchor(): void
    {
        $schedule = IntervalSchedule::every(Duration::minutes(5));

        self::assertEquals(self::createMonotonicTimeAt(self::ANCHOR + 300_000), $schedule->findNextDueAfter(self::createMonotonicTimeAt(self::ANCHOR), self::createMonotonicTimeAt(self::ANCHOR)));
    }

    public function testOccurrenceIsStrictlyAfterTheGivenMoment(): void
    {
        $schedule = IntervalSchedule::every(Duration::minutes(5));

        self::assertEquals(self::createMonotonicTimeAt(self::ANCHOR + 600_000), $schedule->findNextDueAfter(self::createMonotonicTimeAt(self::ANCHOR), self::createMonotonicTimeAt(self::ANCHOR + 300_000)));
    }

    public function testAnchorHoldsThePhaseSoASlowRunDoesNotDrift(): void
    {
        $schedule = IntervalSchedule::every(Duration::minutes(5));

        self::assertEquals(self::createMonotonicTimeAt(self::ANCHOR + 600_000), $schedule->findNextDueAfter(self::createMonotonicTimeAt(self::ANCHOR), self::createMonotonicTimeAt(self::ANCHOR + 540_000)));
    }

    public function testLongGapSkipsStraightToTheNextSlotOnTheGrid(): void
    {
        $schedule = IntervalSchedule::every(Duration::minutes(5));

        self::assertEquals(
            self::createMonotonicTimeAt(self::ANCHOR + 6 * 3_600_000 + 300_000),
            $schedule->findNextDueAfter(self::createMonotonicTimeAt(self::ANCHOR), self::createMonotonicTimeAt(self::ANCHOR + 6 * 3_600_000 + 120_000)),
        );
    }

    public function testMissedCountsWholePeriodsPastServedOne(): void
    {
        $schedule = IntervalSchedule::every(Duration::seconds(10));

        self::assertSame(0, $schedule->countMissedBetween(self::createMonotonicTimeAt(10_000), self::createMonotonicTimeAt(10_004)), 'Timer jitter is not a missed run.');
        self::assertSame(3, $schedule->countMissedBetween(self::createMonotonicTimeAt(10_000), self::createMonotonicTimeAt(45_000)));
    }

    public function testPeriodShorterThanAMillisecondIsRejected(): void
    {
        $this->assertThrowsReason(TimeError::DurationNotPositive, fn() => IntervalSchedule::every(Duration::zero()));
    }

    public function testItRecursAndDescribesItself(): void
    {
        $schedule = IntervalSchedule::every(Duration::seconds(30));

        self::assertTrue($schedule->isRecurring());
        self::assertSame('every 30s', $schedule->describe());
    }

    private static function createMonotonicTimeAt(int $milliseconds): MonotonicTime
    {
        return MonotonicTime::fromMicroseconds($milliseconds * 1_000);
    }
}
