<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Unit\Schedule;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ScheduleError;
use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\PendingWait;
use Stewart\Contracts\Time\SunOffset;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\HaContext\RecordingHaContext;
use Stewart\Testing\Schedule\ManualEntityTimeTask;
use Stewart\Testing\Schedule\ManualScheduledTask;
use Stewart\Testing\Schedule\ManualScheduler;
use Stewart\Testing\Sun\FixedSunCalendar;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

use function Amp\async;

#[CoversClass(ManualScheduler::class)]
#[CoversClass(ManualScheduledTask::class)]
#[CoversClass(ManualEntityTimeTask::class)]
final class ManualSchedulerTest extends TestCase
{
    use AssertsReason;

    private ManualTimers $timers;

    private RecordingHaContext $ha;

    private ManualScheduler $scheduler;

    /** @var list<string> */
    private array $runs = [];

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->ha = new RecordingHaContext($this->timers);
        $this->scheduler = new ManualScheduler($this->timers, new FixedSunCalendar($this->ha->clock), $this->ha);
        $this->runs = [];
    }

    public function testIntervalRunsOncePerPeriod(): void
    {
        $task = $this->scheduler->runEvery(Duration::minutes(10), $this->record('every'));

        $this->advance(Duration::minutes(35));

        self::assertSame(['every@00:10', 'every@00:20', 'every@00:30'], $this->runs);
        self::assertSame('2026-01-01T00:40:00+00:00', $task->getNextRunAt()?->toDateTime($this->ha->clock->getTimeZone())->format(DATE_ATOM));
    }

    public function testDelayRunsOnceAndEnds(): void
    {
        $task = $this->scheduler->runAfter(Duration::minutes(5), $this->record('after'));

        $this->advance(Duration::hours(1));

        self::assertSame(['after@00:05'], $this->runs);
        self::assertFalse($task->isActive());
        self::assertNull($task->getNextRunAt());
    }

    public function testWallClockSchedulesRunInDueOrder(): void
    {
        $this->scheduler->runAfter(Duration::minutes(20), $this->record('after'));
        $this->scheduler->runDailyAt('00:05', $this->record('daily'));
        $this->scheduler->runOnCron('*/15 * * * *', $this->record('cron'));
        $this->scheduler->runAt(new DateTimeImmutable('2026-01-01 00:25:00 UTC'), $this->record('at'));

        $this->advance(Duration::minutes(30));

        self::assertSame(['daily@00:05', 'cron@00:15', 'after@00:20', 'at@00:25', 'cron@00:30'], $this->runs);
    }

    public function testMomentInThePastNeverRuns(): void
    {
        $task = $this->scheduler->runAt(new DateTimeImmutable('2025-12-31 23:00:00 UTC'), $this->record('at'));

        $this->advance(Duration::hours(24));

        self::assertSame([], $this->runs);
        self::assertFalse($task->isActive());
    }

    public function testSunEventsFollowTheCalendarAndOffset(): void
    {
        $this->scheduler->runAtSunEvent(SunEvent::Sunrise, $this->record('sunrise'), SunOffset::before(Duration::minutes(30)));
        $this->scheduler->runAtSunEvent(SunEvent::Sunset, $this->record('sunset'));

        $this->advance(Duration::hours(24));

        self::assertSame(['sunrise@05:30', 'sunset@18:00'], $this->runs);
    }

    public function testEntityTimeFollowsTheEntity(): void
    {
        $this->ha->seedState('input_datetime.wake_up', '07:30:00', ['has_date' => false, 'has_time' => true]);
        $task = $this->scheduler->runAtEntityTime('input_datetime.wake_up', $this->record('wake'));

        $this->advance(Duration::hours(6));
        $this->ha->pushState('input_datetime.wake_up', '08:00:00', ['has_date' => false, 'has_time' => true]);
        $this->advance(Duration::hours(3));

        self::assertSame(['wake@08:00'], $this->runs);
        self::assertSame('manual:0', $task->getId());
    }

    public function testEntityTimeRefusesOtherDomains(): void
    {
        $this->assertThrowsReason(ScheduleError::EntityTimeDomainUnsupported, fn() => $this->scheduler->runAtEntityTime('light.hall', $this->record('never')));
    }

    public function testCancelledTasksNeverRun(): void
    {
        $this->scheduler->runEvery(Duration::minutes(10), $this->record('every'))->cancel();
        $this->scheduler->runDailyAt('01:00', $this->record('daily'));
        $this->ha->seedState('input_datetime.wake_up', '02:00:00', ['has_date' => false, 'has_time' => true]);
        $this->scheduler->runAtEntityTime('input_datetime.wake_up', $this->record('wake'));

        $this->scheduler->cancelAll();
        $this->advance(Duration::hours(24));

        self::assertSame([], $this->runs);
    }

    public function testHandlerThatWaitsDoesNotHoldBackTime(): void
    {
        $this->scheduler->runAfter(Duration::minutes(1), function (): void {
            $this->runs[] = 'door ' . ($this->ha->waitForState('binary_sensor.door', 'off', Duration::minutes(5))->state ?? 'still open');
        });
        $this->scheduler->runAfter(Duration::minutes(2), $this->record('after'));

        $this->advance(Duration::minutes(10));

        self::assertSame(['after@00:02', 'door still open'], $this->runs);
    }

    public function testWaitEndsWhenResolvedOrAtItsTimeout(): void
    {
        $resolved = async(fn(): mixed => $this->scheduler->waitFor(function (PendingWait $wait): Closure {
            $this->scheduler->runAfter(Duration::minutes(1), static fn() => $wait->resolve('arrived'));

            return static fn() => null;
        }, Duration::minutes(5)));
        $expired = async(fn(): mixed => $this->scheduler->waitFor(static fn(PendingWait $wait): Closure => static fn() => null, Duration::minutes(5)));
        EventLoopTicks::settle();

        $this->advance(Duration::minutes(10));

        self::assertSame('arrived', $resolved->await());
        self::assertNull($expired->await());
    }

    /** @return Closure(ScheduledRun): void */
    private function record(string $label): Closure
    {
        return function (ScheduledRun $run) use ($label): void {
            $this->runs[] = $label . '@' . $run->scheduledFor->toDateTime($this->ha->clock->getTimeZone())->format('H:i');
        };
    }

    private function advance(Duration $by): void
    {
        $this->timers->delay($by);
    }
}
