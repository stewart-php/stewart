<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ScheduleError;
use Stewart\Contracts\Schedule\CalendarSchedule;
use Stewart\Contracts\Schedule\CronSchedule;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(CronSchedule::class)]
final class CronScheduleTest extends TestCase
{
    use AssertsReason;

    private const string ZONE = 'Europe/Budapest';

    public function testItFindsTheNextOccurrence(): void
    {
        $next = CronSchedule::parse('0 7 * * *')->findNextOccurrenceAfter($this->createLocalMoment('2026-06-01 06:00:00'));

        self::assertNotNull($next);
        self::assertSame('2026-06-01 07:00:00 +02:00', $next->format('Y-m-d H:i:s P'));
    }

    public function testItHonorsDayOfWeekRestrictions(): void
    {
        // 2026-06-06 is a Saturday, so a weekday expression lands on the Monday.
        $next = CronSchedule::parse('0 7 * * 1-5')->findNextOccurrenceAfter($this->createLocalMoment('2026-06-06 06:00:00'));

        self::assertNotNull($next);
        self::assertSame('2026-06-08 07:00:00 +02:00', $next->format('Y-m-d H:i:s P'));
    }

    public function testOccurrencesResolveInTheSuppliedTimezone(): void
    {
        $after = new DateTimeImmutable('2026-06-01 06:00:00', new DateTimeZone('Asia/Kolkata'));

        $next = CronSchedule::parse('0 7 * * *')->findNextOccurrenceAfter($after);

        self::assertNotNull($next);
        self::assertSame('2026-06-01 07:00:00 +05:30', $next->format('Y-m-d H:i:s P'));
    }

    public function testItSkipsAWallTimeTheClockJumpedOver(): void
    {
        $next = CronSchedule::parse('30 2 * * *')->findNextOccurrenceAfter($this->createLocalMoment('2026-03-28 12:00:00'));

        self::assertNotNull($next);
        self::assertSame(
            '2026-03-30 02:30:00 +02:00',
            $next->format('Y-m-d H:i:s P'),
            'The library on its own would shift this to 03:30 on the 29th.',
        );
    }

    public function testItFiresOnceForAWallTimeThatHappensTwice(): void
    {
        $schedule = CronSchedule::parse('30 2 * * *');

        $first = $schedule->findNextOccurrenceAfter($this->createLocalMoment('2026-10-24 12:00:00'));

        self::assertNotNull($first);
        self::assertSame(1_792_888_200, $first->getTimestamp());

        $second = $schedule->findNextOccurrenceAfter($first);

        self::assertNotNull($second);
        self::assertSame(
            '2026-10-26 02:30:00 +01:00',
            $second->format('Y-m-d H:i:s P'),
            'The repeat of 02:30 later the same morning is not a second occurrence.',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function provideSubHourlyExpressions(): iterable
    {
        yield 'every minute' => ['* * * * *'];
        yield 'every five minutes' => ['*/5 * * * *'];
    }

    #[DataProvider('provideSubHourlyExpressions')]
    public function testSubHourlyCronHasNextRunInRepeatedHour(string $expression): void
    {
        // 02:10 at +01:00: every wall time up to 03:00 already happened once, at +02:00.
        $after = new DateTimeImmutable('@1792890600')->setTimezone(new DateTimeZone(self::ZONE));

        self::assertSame('2026-10-25 02:10:00 +01:00', $after->format('Y-m-d H:i:s P'));

        $next = CronSchedule::parse($expression)->findNextOccurrenceAfter($after);

        self::assertNotNull($next, 'A null here would cancel the schedule for good.');
        self::assertSame('2026-10-25 03:00:00 +01:00', $next->format('Y-m-d H:i:s P'));
    }

    public function testRepeatedHourIsAGapForASubHourlyExpression(): void
    {
        // 02:45 at +02:00, the first pass of the hour.
        $after = new DateTimeImmutable('@1792889100')->setTimezone(new DateTimeZone(self::ZONE));

        $next = CronSchedule::parse('*/15 * * * *')->findNextOccurrenceAfter($after);

        self::assertNotNull($next);
        self::assertSame('2026-10-25 03:00:00 +01:00', $next->format('Y-m-d H:i:s P'));
        self::assertSame(75 * 60, $next->getTimestamp() - $after->getTimestamp(), 'One daylight saving rule: the repeat does not run.');
    }

    public function testSubHourlyCronSkipsHourLostToDst(): void
    {
        $next = CronSchedule::parse('*/15 * * * *')->findNextOccurrenceAfter($this->createLocalMoment('2026-03-29 01:50:00'));

        self::assertNotNull($next);
        self::assertSame('2026-03-29 03:00:00 +02:00', $next->format('Y-m-d H:i:s P'));
    }

    public function testMatchesCalendarScheduleAcrossDstTransitions(): void
    {
        $cron = CronSchedule::parse('30 2 * * *');
        $calendar = CalendarSchedule::dailyAt('02:30');

        foreach (['2026-03-28 12:00:00', '2026-10-24 12:00:00'] as $from) {
            $after = $this->createLocalMoment($from);

            self::assertEquals(
                $calendar->findNextOccurrenceAfter($after),
                $cron->findNextOccurrenceAfter($after),
                'One daylight saving policy, whichever way the schedule was written.',
            );
        }
    }

    public function testInvalidExpressionIsRejectedWhereItIsWritten(): void
    {
        $this->assertThrowsReason(ScheduleError::CronInvalid, fn() => CronSchedule::parse('not a cron'));
    }

    public function testExpressionNoCalendarCanSatisfyNeverOccurs(): void
    {
        self::assertNull(
            CronSchedule::parse('0 0 30 2 *')->findNextOccurrenceAfter($this->createLocalMoment('2026-06-01 00:00:00')),
            'There is no 30 February.',
        );
    }

    public function testItDescribesItself(): void
    {
        self::assertSame('cron "0 7 * * *"', CronSchedule::parse('0 7 * * *')->describe());
        self::assertTrue(CronSchedule::parse('0 7 * * *')->isRecurring());
    }

    private function createLocalMoment(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new DateTimeZone(self::ZONE));
    }
}
