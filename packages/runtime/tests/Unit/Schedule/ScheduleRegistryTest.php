<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Schedule;

use Amp\DeferredFuture;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Schedule\ElapsedSchedule;
use Stewart\Contracts\Schedule\IntervalSchedule;
use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\Schedule\WallClockSchedule;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Schedule\AsyncHandlerRunner;
use Stewart\Runtime\Schedule\ElapsedTrigger;
use Stewart\Runtime\Schedule\ScheduleContext;
use Stewart\Runtime\Schedule\ScheduledTaskHandle;
use Stewart\Runtime\Schedule\ScheduleEntry;
use Stewart\Runtime\Schedule\ScheduleOrigin;
use Stewart\Runtime\Schedule\ScheduleRegistry;
use Stewart\Runtime\Schedule\TriggerFactory;
use Stewart\Runtime\Schedule\WallTrigger;
use Stewart\Runtime\Schedule\WorkerScheduler;
use Stewart\Runtime\Scope\ScopeLifecycle;
use Stewart\Runtime\Tests\Fixtures\Schedule\WorkerSchedulerFixture;
use Stewart\Runtime\Tests\Fixtures\Worker\InlineHandlerRunner;
use Stewart\Runtime\Tests\Fixtures\Worker\RecordingScheduleListener;
use Stewart\Sun\UnlocatedSunCalendar;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(ScheduleRegistry::class)]
#[CoversClass(ScheduleContext::class)]
#[CoversClass(ScheduleEntry::class)]
#[CoversClass(ScheduledTaskHandle::class)]
#[CoversClass(ScheduleOrigin::class)]
#[CoversClass(WallTrigger::class)]
#[CoversClass(ElapsedTrigger::class)]
#[CoversClass(WorkerScheduler::class)]
final class ScheduleRegistryTest extends TestCase
{
    private ManualTimers $timers;

    private VirtualClock $clock;

    private RecordingScheduleListener $listener;

    private RecordingLogger $logger;

    private ScopeLifecycle $scopes;

    private ScheduleRegistry $registry;

    private WorkerScheduler $scheduler;

    /** @var list<ScheduledRun> */
    private array $runs = [];

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->clock = $this->timers->clock;
        $this->listener = new RecordingScheduleListener();
        $this->logger = new RecordingLogger();
        $this->scopes = new ScopeLifecycle();
        $this->registry = new ScheduleRegistry('w0', new ScheduleContext($this->timers, $this->clock, new InlineHandlerRunner(), $this->listener), new TriggerFactory($this->clock), $this->scopes);
        $this->scheduler = WorkerSchedulerFixture::createWorkerScheduler($this->registry, new UnlocatedSunCalendar(), $this->logger, self::createScope('demo'));
        $this->runs = [];
    }

    public function testSchedulesAreCountedPerAppUntilCancelled(): void
    {
        $task = $this->scheduler->runEvery(Duration::seconds(10), $this->record(...));

        self::assertSame(1, $this->registry->countFor(self::createScope('demo')));
        self::assertSame(0, $this->registry->countFor(self::createScope('nobody')));

        $task->cancel();

        self::assertSame(0, $this->registry->countFor(self::createScope('demo')), 'A cancelled schedule is forgotten.');
    }

    public function testNothingFiresBeforeTheAppGoesLive(): void
    {
        $task = $this->scheduler->runEvery(Duration::seconds(10), $this->record(...));

        $this->timers->delay(Duration::minutes(5));

        self::assertSame([], $this->runs, 'Apps are constructed before the state cache is seeded.');
        self::assertTrue($task->isActive());
        self::assertNull($task->getNextRunAt(), 'A dormant task has no next run yet.');
    }

    public function testElapsedScheduleCountsFromGoingLive(): void
    {
        $this->scheduler->runEvery(Duration::seconds(10), $this->record(...));

        $this->timers->delay(Duration::seconds(7));
        $this->goLive('demo');
        $this->timers->delay(Duration::seconds(10));

        self::assertSame(['00:00:17'], $this->listScheduledTimes());
    }

    public function testEveryOccurrenceInOneAdvanceIsServed(): void
    {
        $this->goLive('demo');
        $this->scheduler->runEvery(Duration::seconds(10), $this->record(...));

        $this->timers->delay(Duration::minutes(1));

        self::assertSame(
            ['00:00:10', '00:00:20', '00:00:30', '00:00:40', '00:00:50', '00:01:00'],
            $this->listScheduledTimes(),
        );
        self::assertSame([0, 0, 0, 0, 0, 0], array_map(static fn(ScheduledRun $run): int => $run->missedOccurrences, $this->runs));
    }

    public function testIntervalSurvivesClockStepBack(): void
    {
        $this->goLive('demo');
        $this->scheduler->runEvery(Duration::seconds(10), $this->record(...));

        $this->timers->delay(Duration::seconds(20));
        $this->clock->rewind(Duration::minutes(10));
        $this->timers->delay(Duration::seconds(30));

        self::assertCount(5, $this->runs, 'Elapsed time does not follow the wall clock.');
    }

    public function testWallScheduleRunsOnceAfterClockStepBack(): void
    {
        $this->goLive('demo');
        $this->scheduler->runDailyAt('00:01', $this->record(...));

        $this->timers->delay(Duration::minutes(1));
        $this->clock->rewind(Duration::minutes(5));
        $this->timers->delay(Duration::minutes(10));

        self::assertCount(1, $this->runs);
    }

    public function testLongWaitFiresAtOccurrence(): void
    {
        $this->goLive('demo');
        $this->scheduler->runAt($this->createWallTimeAt('03:00:00'), $this->record(...));

        $this->timers->delay(Duration::hours(2));

        self::assertCount(0, $this->runs, 'Every hop wake-up so far was early.');

        $this->timers->delay(Duration::hours(1));

        self::assertSame(['03:00:00'], $this->listScheduledTimes());
        self::assertSame(0, $this->runs[0]->getLateness()->toMilliseconds());
    }

    public function testForwardWallStepIsNoticedWithinOneHop(): void
    {
        $this->goLive('demo');
        $this->scheduler->runAt($this->createWallTimeAt('02:00:00'), $this->record(...));

        $this->clock->skip(Duration::hours(3));
        $this->timers->delay(Duration::minutes(1));

        self::assertCount(1, $this->runs);
        self::assertSame(3_600_000 + 60_000, $this->runs[0]->getLateness()->toMilliseconds());
    }

    public function testMissedOccurrencesAreSkippedAndCounted(): void
    {
        $this->goLive('demo');
        $this->scheduler->runOnCron('* * * * *', $this->record(...));

        $this->clock->skip(Duration::hours(1));
        $this->timers->delay(Duration::minutes(1));

        self::assertCount(1, $this->runs, 'Sixty occurrences elapsed; one run happened.');
        self::assertSame(60, $this->runs[0]->missedOccurrences);
        self::assertContains(
            'A scheduled run started late; the occurrences it overran were skipped',
            $this->logger->listMessagesAt('info'),
        );
    }

    public function testCronSurvivesDaylightSavingNight(): void
    {
        // Clocks go back 03:00 +02:00 -> 02:00 +01:00 on 2026-10-25, so 02:00-02:59 happens twice.
        $zone = new DateTimeZone('Europe/Budapest');
        $timers = new ManualTimers(new VirtualClock(new DateTimeImmutable('2026-10-25 01:30:00', $zone)));
        $registry = new ScheduleRegistry('w0', new ScheduleContext($timers, $timers->clock, new InlineHandlerRunner(), $this->listener), new TriggerFactory($timers->clock), $this->scopes);
        $scheduler = WorkerSchedulerFixture::createWorkerScheduler($registry, new UnlocatedSunCalendar(), $this->logger, self::createScope('demo'));

        $this->goLive('demo', $registry);
        $scheduler->runOnCron('*/5 * * * *', $this->record(...));

        $timers->delay(Duration::hours(3));

        $wallTimes = array_map(static fn(ScheduledRun $run): string => $run->scheduledFor->toDateTime($zone)->format('H:i P'), $this->runs);
        $transition = array_search('02:55 +02:00', $wallTimes, true);

        self::assertSame('01:35 +02:00', $wallTimes[0], 'The first occurrence is before the transition.');
        self::assertSame('03:30 +01:00', end($wallTimes), 'And the last one is after it.');
        self::assertIsInt($transition);
        self::assertSame('03:00 +01:00', $wallTimes[$transition + 1], 'The repeated hour is not served a second time.');
        self::assertSame(
            65 * 60,
            $this->runs[$transition + 1]->scheduledFor->elapsedSince($this->runs[$transition]->scheduledFor)->toMilliseconds() / 1_000,
            'One daylight saving rule: the gap is the repeat that did not run.',
        );

        $clockTimes = array_map(static fn(ScheduledRun $run): string => $run->scheduledFor->toDateTime($zone)->format('H:i'), $this->runs);

        self::assertSame($clockTimes, array_values(array_unique($clockTimes)), 'No wall time occurs twice.');
    }

    public function testCountingMissedWallOccurrencesIsCapped(): void
    {
        $this->goLive('demo');
        $this->scheduler->runOnCron('* * * * *', $this->record(...));

        $this->clock->skip(Duration::hours(5));
        $this->timers->delay(Duration::minutes(1));

        self::assertSame(100, $this->runs[0]->missedOccurrences);
    }

    public function testTimerJitterIsNotReportedAsMissedRuns(): void
    {
        $this->goLive('demo');
        $this->scheduler->runEvery(Duration::seconds(10), $this->record(...));

        $this->timers->delay(Duration::seconds(10));

        self::assertSame(0, $this->runs[0]->missedOccurrences);
        self::assertSame([], $this->logger->listMessagesAt('info'));
    }

    public function testOccurrenceDueDuringStartupRunsOnceLive(): void
    {
        $oneShot = $this->scheduler->runAt($this->createWallTimeAt('00:00:05'), $this->record(...));
        $daily = $this->scheduler->runDailyAt('00:00:05', $this->record(...));

        $this->timers->delay(Duration::seconds(10));
        $this->goLive('demo');
        $this->timers->delay(Duration::milliseconds(1));

        self::assertSame(['00:00:05', '00:00:05'], $this->listScheduledTimes());
        self::assertSame(5_001, $this->runs[0]->getLateness()->toMilliseconds());
        self::assertFalse($oneShot->isActive());
        self::assertSame('2026-01-02 00:00:05', $daily->getNextRunAt()?->toDateTime(self::getUtcZone())->format('Y-m-d H:i:s'));
    }

    public function testMomentAlreadyPastIsNotArmed(): void
    {
        $this->goLive('demo');

        $task = $this->scheduler->runAt($this->createWallTimeAt('00:00:00')->modify('-1 second'), $this->record(...));

        self::assertFalse($task->isActive());
        self::assertSame(0, $this->registry->count());
        self::assertSame(['A schedule never occurs; it was not armed'], $this->logger->listMessagesAt('warning'));
    }

    public function testCronNoCalendarCanSatisfyIsNotArmed(): void
    {
        $task = $this->scheduler->runOnCron('0 0 30 2 *', $this->record(...));

        self::assertFalse($task->isActive(), 'Refused when armed, not only once the app goes live.');
        self::assertSame(['A schedule never occurs; it was not armed'], $this->logger->listMessagesAt('warning'));
    }

    public function testExhaustedRecurringScheduleWarns(): void
    {
        $this->goLive('demo');
        $this->armForDemo($this->createScheduleEndingAfter($this->createWallTimeAt('00:00:10')), $this->record(...));
        $this->scheduler->runAt($this->createWallTimeAt('00:00:10'), $this->record(...));

        $this->timers->delay(Duration::seconds(10));

        self::assertCount(2, $this->runs);
        self::assertSame(['A schedule has no further occurrences and has stopped'], $this->logger->listMessagesAt('warning'));
    }

    public function testCustomOneShotThatRunsOutDoesNotWarn(): void
    {
        $this->goLive('demo');
        $this->armForDemo($this->createScheduleEndingAfter($this->createWallTimeAt('00:00:10'), recurring: false), $this->record(...));

        $this->timers->delay(Duration::seconds(10));

        self::assertCount(1, $this->runs);
        self::assertSame([], $this->logger->listMessagesAt('warning'));
    }

    public function testIntervalScheduleRunsOnElapsedClock(): void
    {
        $this->goLive('demo');
        $this->armForDemo(IntervalSchedule::every(Duration::seconds(10)), $this->record(...));

        $this->clock->rewind(Duration::minutes(10));
        $this->timers->delay(Duration::seconds(20));

        self::assertCount(2, $this->runs, 'A wall clock step back does not hold up an elapsed schedule.');
    }

    public function testThrowingScheduleIsCancelledAndReported(): void
    {
        $this->goLive('demo');
        $broken = $this->armForDemo($this->createScheduleThrowingFrom($this->createWallTimeAt('00:00:10')), $this->record(...));
        $this->scheduler->runEvery(Duration::seconds(5), $this->record(...));

        $this->timers->delay(Duration::minutes(1));

        self::assertFalse($broken->isActive());
        self::assertCount(1, $this->listener->failedSchedules);
        self::assertSame('demo', (string) $this->listener->failedSchedules[0]->scope);
        self::assertCount(12, $this->runs, 'Only the interval runs; the broken schedule stops before its due occurrence.');
    }

    public function testScheduleThrowingOnArmIsReported(): void
    {
        $task = $this->armForDemo($this->createScheduleThrowingFrom($this->createWallTimeAt('00:00:00')), $this->record(...));

        self::assertFalse($task->isActive());
        self::assertCount(1, $this->listener->failedSchedules);
        self::assertSame(0, $this->registry->count());
    }

    public function testThrowingHandlerKeepsScheduleArmed(): void
    {
        $this->goLive('demo');
        $task = $this->scheduler->runEvery(Duration::seconds(10), function (ScheduledRun $run): void {
            $this->runs[] = $run;

            throw new RuntimeException('the automation is broken');
        });

        $this->timers->delay(Duration::seconds(20));

        self::assertCount(2, $this->runs);
        self::assertCount(2, $this->listener->failedRuns);
        self::assertSame('schedule w0:0 (every 10s)', $this->listener->failedRuns[0]->label());
        self::assertTrue($task->isActive());
    }

    public function testRunInFlightDisplacesNextAndWarnsOnce(): void
    {
        $registry = new ScheduleRegistry('w0', new ScheduleContext($this->timers, $this->clock, new AsyncHandlerRunner(), $this->listener), new TriggerFactory($this->clock), $this->scopes);
        $scheduler = WorkerSchedulerFixture::createWorkerScheduler($registry, new UnlocatedSunCalendar(), $this->logger, self::createScope('demo'));
        $gate = new DeferredFuture();

        $this->goLive('demo', $registry);
        $scheduler->runEvery(Duration::seconds(10), function (ScheduledRun $run) use ($gate): void {
            $this->runs[] = $run;
            $gate->getFuture()->await();
        });

        $this->advanceAndYield(Duration::seconds(10));
        $this->advanceAndYield(Duration::seconds(10));
        $this->advanceAndYield(Duration::seconds(10));

        self::assertCount(1, $this->runs, 'The first run has not returned, so the next two are dropped.');
        self::assertSame(['Skipped a scheduled run; the previous one has not finished'], $this->logger->listMessagesAt('warning'));

        $gate->complete();
        EventLoopTicks::settle();

        $this->advanceAndYield(Duration::seconds(10));

        self::assertCount(2, $this->runs, 'Once the handler returns, the schedule picks up again.');
    }

    public function testOneShotFiresOnceAndReleasesItself(): void
    {
        $this->goLive('demo');
        $task = $this->scheduler->runAfter(Duration::seconds(30), $this->record(...));

        $this->timers->delay(Duration::minutes(5));

        self::assertCount(1, $this->runs);
        self::assertFalse($task->isActive());
        self::assertNull($task->getNextRunAt());
        self::assertSame(0, $this->registry->count());
        self::assertSame([], $this->logger->listMessagesAt('warning'));
    }

    public function testHandlerCanCancelItsOwnTask(): void
    {
        $this->goLive('demo');
        $task = $this->scheduler->runEvery(Duration::seconds(10), function (ScheduledRun $run): void {
            $this->runs[] = $run;
            $run->task->cancel();
        });

        $this->timers->delay(Duration::minutes(1));

        self::assertCount(1, $this->runs);
        self::assertFalse($task->isActive());
    }

    public function testCancelStopsOnlyThatTask(): void
    {
        $this->goLive('demo');
        $cancelled = $this->scheduler->runEvery(Duration::seconds(10), $this->record(...));
        $this->scheduler->runEvery(Duration::seconds(10), $this->record(...));

        $cancelled->cancel();

        self::assertFalse($cancelled->isActive());
        self::assertNull($cancelled->getNextRunAt());

        $this->timers->delay(Duration::seconds(10));

        self::assertCount(1, $this->runs);
        self::assertSame(1, $this->registry->count());
    }

    public function testReleasingAnAppLeavesAnotherAppsSchedulesAlone(): void
    {
        $this->goLive('demo');
        $this->goLive('echo');
        $this->scheduler->runEvery(Duration::seconds(10), $this->record(...));
        $this->scheduler->forApp(new AppId('echo'), $this->logger)->runEvery(Duration::seconds(10), $this->record(...));

        $this->release('demo');

        $this->timers->delay(Duration::seconds(10));

        self::assertCount(1, $this->runs);
    }

    public function testReleasedAppCannotArmAgain(): void
    {
        $this->goLive('demo');
        $this->release('demo');

        $late = $this->scheduler->runEvery(Duration::seconds(10), $this->record(...));
        $this->timers->delay(Duration::minutes(1));

        self::assertFalse($late->isActive());
        self::assertSame([], $this->runs);
        self::assertSame(['Ignored a schedule armed after its app stopped'], $this->logger->listMessagesAt('debug'));
    }

    public function testReleasingEverythingAlsoRefusesLaterArming(): void
    {
        $this->goLive('demo');
        $this->scheduler->runEvery(Duration::seconds(10), $this->record(...));
        $this->scheduler->runDailyAt('07:00', $this->record(...));

        $this->releaseEverything();

        $late = $this->scheduler->runEvery(Duration::seconds(10), $this->record(...));

        self::assertFalse($late->isActive());

        $this->timers->delay(Duration::minutes(5));

        self::assertSame([], $this->runs);
        self::assertSame(0, $this->timers->countPendingTimers(), 'A stopped registry leaves no timer behind.');
    }

    public function testHandlerMayScheduleMoreWork(): void
    {
        $this->goLive('demo');
        $this->scheduler->runAfter(Duration::seconds(10), function (ScheduledRun $run): void {
            $this->runs[] = $run;
            $this->scheduler->runAfter(Duration::seconds(10), $this->record(...));
        });

        $this->timers->delay(Duration::seconds(20));

        self::assertSame(['00:00:10', '00:00:20'], $this->listScheduledTimes());
    }

    public function testNextRunAtDuringAHandlerIsTheRunAfterThisOne(): void
    {
        $seen = null;

        $this->goLive('demo');
        $this->scheduler->runEvery(Duration::seconds(10), static function (ScheduledRun $run) use (&$seen): void {
            $seen = $run->task->getNextRunAt();
        });

        $this->timers->delay(Duration::seconds(10));

        self::assertInstanceOf(Instant::class, $seen);
        self::assertSame('00:00:20', $seen->toDateTime(self::getUtcZone())->format('H:i:s'), 'Re-arming happens before the handler runs.');
    }

    public function testElapsedNextRunAtIsWallClockTime(): void
    {
        $task = $this->scheduler->runAfter(Duration::seconds(30), $this->record(...));

        $this->goLive('demo');
        $this->clock->skip(Duration::hours(1));

        self::assertSame('01:00:30', $task->getNextRunAt()?->toDateTime(self::getUtcZone())->format('H:i:s'));
    }

    public function testPausedScheduleSkipsThenRunsAfterResume(): void
    {
        $task = $this->scheduler->runEvery(Duration::seconds(10), $this->record(...));
        $this->goLive('demo');
        $this->pause('demo');

        $this->timers->delay(Duration::seconds(20));

        self::assertSame([], $this->runs);
        self::assertCount(2, $this->listener->suppressed, 'Each firing while paused is suppressed, not deferred.');
        self::assertTrue($task->isActive());

        $this->resume('demo');
        $this->timers->delay(Duration::seconds(10));

        self::assertSame(['00:00:30'], $this->listScheduledTimes());
    }

    public function testOneShotDueWhilePausedIsDropped(): void
    {
        $this->goLive('demo');
        $task = $this->scheduler->runAfter(Duration::seconds(30), $this->record(...));
        $this->pause('demo');

        $this->timers->delay(Duration::minutes(1));
        $this->resume('demo');
        $this->timers->delay(Duration::minutes(1));

        self::assertSame([], $this->runs);
        self::assertFalse($task->isActive());
        self::assertSame(0, $this->registry->count());
    }

    public function testScheduleArmedWhilePausedStartsPaused(): void
    {
        $this->goLive('demo');
        $this->pause('demo');

        $this->scheduler->runEvery(Duration::seconds(10), $this->record(...));
        $this->timers->delay(Duration::seconds(10));

        self::assertSame([], $this->runs);
        self::assertCount(1, $this->listener->suppressed);
    }

    private function goLive(string $appId, ?ScheduleRegistry $registry = null): void
    {
        $this->scopes->activateScope(self::createScope($appId));
        ($registry ?? $this->registry)->startEntriesOf(self::createScope($appId));
    }

    private function pause(string $appId): void
    {
        $this->scopes->pauseScope(self::createScope($appId));
        $this->registry->pauseEntriesOf(self::createScope($appId));
    }

    private function resume(string $appId): void
    {
        $this->scopes->resumeScope(self::createScope($appId));
        $this->registry->resumeEntriesOf(self::createScope($appId));
    }

    private function release(string $appId): void
    {
        $this->scopes->releaseScope(self::createScope($appId));
        $this->registry->cancelEntriesOf(self::createScope($appId));
    }

    private function releaseEverything(): void
    {
        $this->scopes->stopAll();
        $this->registry->cancelAll();
    }

    private function advanceAndYield(Duration $by): void
    {
        $this->timers->delay($by);

        EventLoopTicks::settle();
    }

    private function record(ScheduledRun $run): void
    {
        $this->runs[] = $run;
    }

    /** @return list<string> */
    private function listScheduledTimes(): array
    {
        return array_map(static fn(ScheduledRun $run): string => $run->scheduledFor->toDateTime(self::getUtcZone())->format('H:i:s'), $this->runs);
    }

    private function createWallTimeAt(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01 ' . $time, new DateTimeZone('UTC'));
    }

    private function createScheduleEndingAfter(DateTimeImmutable $only, bool $recurring = true): WallClockSchedule
    {
        return new readonly class ($only, $recurring) implements WallClockSchedule {
            public function __construct(
                private DateTimeImmutable $only,
                private bool $recurring,
            ) {}

            public function findNextOccurrenceAfter(DateTimeImmutable $after): ?DateTimeImmutable
            {
                return $after < $this->only ? $this->only : null;
            }

            public function describe(): string
            {
                return 'ends early';
            }

            public function isRecurring(): bool
            {
                return $this->recurring;
            }
        };
    }

    private function createScheduleThrowingFrom(DateTimeImmutable $from): WallClockSchedule
    {
        return new readonly class ($from) implements WallClockSchedule {
            public function __construct(private DateTimeImmutable $from) {}

            public function findNextOccurrenceAfter(DateTimeImmutable $after): DateTimeImmutable
            {
                if ($after >= $this->from) {
                    throw new RuntimeException('sun entity unavailable');
                }

                return $this->from;
            }

            public function describe(): string
            {
                return 'sunset';
            }

            public function isRecurring(): bool
            {
                return true;
            }
        };
    }

    private static function getUtcZone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    private static function createScope(string $appId): ResourceScope
    {
        return ResourceScope::forApp(new AppId($appId));
    }

    /** @param Closure(ScheduledRun): void $handler */
    private function armForDemo(WallClockSchedule|ElapsedSchedule $schedule, Closure $handler): ScheduledTask
    {
        return $this->registry->arm(self::createScope('demo'), $schedule, $this->logger, $handler);
    }
}
