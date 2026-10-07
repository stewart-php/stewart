<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\ScheduleError;
use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Schedule\EntityTimeScheduler;
use Stewart\Runtime\Schedule\EntityTimeTask;
use Stewart\Runtime\Schedule\WorkerScheduler;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Tests\Fixtures\Schedule\WorkerSchedulerFixture;
use Stewart\Runtime\Tests\Fixtures\Worker\AppResourcesFixture;
use Stewart\Sun\UnlocatedSunCalendar;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(WorkerScheduler::class)]
#[CoversClass(EntityTimeScheduler::class)]
#[CoversClass(EntityTimeTask::class)]
final class WorkerSchedulerEntityTimeTest extends TestCase
{
    use AssertsReason;

    private const string WAKE_UP = 'input_datetime.wake_up';

    private const array DATE_AND_TIME = ['has_date' => true, 'has_time' => true];

    private const array TIME_ONLY = ['has_date' => false, 'has_time' => true];

    private ManualTimers $timers;

    private AppResourcesFixture $fixture;

    private StateCache $states;

    private RecordingLogger $logger;

    private ResourceScope $scope;

    private WorkerScheduler $scheduler;

    /** @var list<ScheduledRun> */
    private array $runs = [];

    protected function setUp(): void
    {
        $this->timers = new ManualTimers(new VirtualClock(new DateTimeImmutable('2026-06-01 06:00:00', new DateTimeZone('Europe/Budapest'))));
        $this->fixture = new AppResourcesFixture($this->timers);
        $this->states = new StateCache();
        $this->logger = new RecordingLogger();
        $this->scope = ResourceScope::forApp(new AppId('alarm'));
        $this->fixture->resources->activateScope($this->scope);
        $this->scheduler = WorkerSchedulerFixture::createWorkerScheduler(
            $this->fixture->schedules,
            new UnlocatedSunCalendar(),
            $this->logger,
            $this->scope,
            $this->timers,
            $this->states,
            $this->fixture->dispatcher,
        );
        $this->runs = [];
    }

    protected function tearDown(): void
    {
        $this->fixture->resources->releaseAll();
    }

    public function testRunsAtEntityDateTime(): void
    {
        $this->changeWakeUp('2026-06-01 07:30:00', self::DATE_AND_TIME);
        $this->scheduleWakeUp();

        $this->timers->delay(Duration::minutes(89));
        self::assertCount(0, $this->runs);

        $this->timers->delay(Duration::minutes(1));
        self::assertCount(1, $this->runs);
    }

    public function testRunsDailyAtEntityTimeOfDay(): void
    {
        $this->changeWakeUp('07:00:00', self::TIME_ONLY);
        $this->scheduleWakeUp();

        $this->timers->delay(Duration::hours(1));
        $this->timers->delay(Duration::hours(24));

        self::assertCount(2, $this->runs);
    }

    public function testRearmsWhenEntityChanges(): void
    {
        $this->changeWakeUp('2026-06-01 07:30:00', self::DATE_AND_TIME);
        $task = $this->scheduleWakeUp();
        $this->changeWakeUp('2026-06-01 08:00:00', self::DATE_AND_TIME);

        $this->timers->delay(Duration::minutes(90));
        self::assertCount(0, $this->runs);
        self::assertSame('2026-06-01 08:00:00', $task->getNextRunAt()?->toDateTime(new DateTimeZone('Europe/Budapest'))->format('Y-m-d H:i:s'));

        $this->timers->delay(Duration::minutes(30));
        self::assertCount(1, $this->runs);
    }

    public function testPastMomentStaysInactiveWithoutWarning(): void
    {
        $this->changeWakeUp('2026-06-01 05:00:00', self::DATE_AND_TIME);

        $task = $this->scheduleWakeUp();

        self::assertFalse($task->isActive());
        self::assertSame([], $this->logger->listMessagesAt(LogLevel::WARNING));
    }

    public function testUnparsableStateArmsOnNextChange(): void
    {
        $this->changeWakeUp(EntityState::UNAVAILABLE);
        $task = $this->scheduleWakeUp();

        self::assertFalse($task->isActive());

        $this->changeWakeUp('2026-06-01 07:30:00', self::DATE_AND_TIME);

        self::assertTrue($task->isActive());
    }

    public function testStaleTimeIsSkippedAtFireTime(): void
    {
        $this->changeWakeUp('2026-06-01 07:30:00', self::DATE_AND_TIME);
        $this->scheduleWakeUp();

        $this->fixture->resources->pauseScope($this->scope);
        $this->changeWakeUp('2026-06-01 08:00:00', self::DATE_AND_TIME);
        $this->fixture->resources->resumeScope($this->scope);

        $this->timers->delay(Duration::minutes(90));
        self::assertCount(0, $this->runs);

        $this->timers->delay(Duration::minutes(30));
        self::assertCount(1, $this->runs);
    }

    public function testCancelDropsInnerTaskAndSubscription(): void
    {
        $this->changeWakeUp('2026-06-01 07:30:00', self::DATE_AND_TIME);
        $task = $this->scheduleWakeUp();

        $task->cancel();
        $this->changeWakeUp('2026-06-01 08:00:00', self::DATE_AND_TIME);
        $this->timers->delay(Duration::hours(3));

        self::assertFalse($task->isActive());
        self::assertSame(0, $this->fixture->schedules->countFor($this->scope));
        self::assertSame(0, $this->fixture->dispatcher->countFor($this->scope));
        self::assertCount(0, $this->runs);
    }

    public function testRunCarriesEntityTimeTask(): void
    {
        $this->changeWakeUp('2026-06-01 07:30:00', self::DATE_AND_TIME);
        $task = $this->scheduleWakeUp();

        $this->timers->delay(Duration::minutes(90));

        self::assertSame($task, $this->runs[0]->task);
    }

    public function testUnsupportedEntityDomainThrows(): void
    {
        $this->assertThrowsReason(ScheduleError::EntityTimeDomainUnsupported, fn() => $this->scheduler->runAtEntityTime('light.hall', static function (): void {}));
    }

    public function testAppStopCancelsEntityTimeTask(): void
    {
        $this->changeWakeUp('2026-06-01 07:30:00', self::DATE_AND_TIME);
        $task = $this->scheduleWakeUp();

        $this->fixture->resources->releaseScope($this->scope);
        $this->changeWakeUp('2026-06-01 08:00:00', self::DATE_AND_TIME);
        $this->timers->delay(Duration::hours(3));

        self::assertFalse($task->isActive());
        self::assertCount(0, $this->runs);
    }

    private function scheduleWakeUp(): ScheduledTask
    {
        return $this->scheduler->runAtEntityTime(self::WAKE_UP, function (ScheduledRun $run): void {
            $this->runs[] = $run;
        });
    }

    /** @param array<string, mixed> $attributes */
    private function changeWakeUp(string $value, array $attributes = []): void
    {
        $id = new EntityId(self::WAKE_UP);
        $change = new StateChange($id, $this->states->find($id), new EntityState($id, $value, $attributes));

        $this->states->applyChange($change);
        $this->fixture->dispatcher->dispatchStateChange($change);
        EventLoopTicks::settle();
    }
}
