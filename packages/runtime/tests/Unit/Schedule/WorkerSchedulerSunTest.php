<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\ScheduleError;
use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\SunOffset;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Schedule\ScheduleContext;
use Stewart\Runtime\Schedule\ScheduleRegistry;
use Stewart\Runtime\Schedule\TriggerFactory;
use Stewart\Runtime\Schedule\WorkerScheduler;
use Stewart\Runtime\Scope\ScopeLifecycle;
use Stewart\Runtime\Tests\Fixtures\Worker\InlineHandlerRunner;
use Stewart\Runtime\Tests\Fixtures\Worker\RecordingScheduleListener;
use Stewart\Sun\LocatedSunCalendar;
use Stewart\Sun\NoaaSolarCalculator;
use Stewart\Sun\UnlocatedSunCalendar;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(WorkerScheduler::class)]
final class WorkerSchedulerSunTest extends TestCase
{
    use AssertsReason;

    private const string SUNRISE = '2026-10-05T04:48:23Z';
    private const string NEXT_SUNRISE = '2026-10-06T04:49:46Z';
    private const string SUNSET = '2026-10-05T16:15:22Z';

    private ManualTimers $timers;

    private ScopeLifecycle $scopes;

    private ScheduleRegistry $registry;

    private LocatedSunCalendar $calendar;

    private WorkerScheduler $scheduler;

    /** @var list<ScheduledRun> */
    private array $runs = [];

    protected function setUp(): void
    {
        $zone = new DateTimeZone('Europe/Budapest');
        $this->timers = new ManualTimers(new VirtualClock(new DateTimeImmutable('2026-10-05 04:00', $zone)));
        $this->scopes = new ScopeLifecycle();
        $this->registry = new ScheduleRegistry(
            'w0',
            new ScheduleContext($this->timers, $this->timers->clock, new InlineHandlerRunner(), new RecordingScheduleListener()),
            new TriggerFactory($this->timers->clock),
            $this->scopes,
        );
        $this->calendar = new LocatedSunCalendar(new GeoLocation(47.4979, 19.0402), $this->timers->clock, new NoaaSolarCalculator());
        $this->scheduler = new WorkerScheduler($this->registry, $this->calendar, new RecordingLogger(), self::createScope());
        $this->runs = [];
    }

    public function testSunriseRunsAndRearmsForNextDay(): void
    {
        $task = $this->scheduler->runAtSunrise($this->record(...));
        $this->goLive();

        self::assertSame(self::SUNRISE, self::formatToSecond($task->getNextRunAt()));

        $this->timers->delay(Duration::hours(3));

        self::assertCount(1, $this->runs);
        self::assertSame(self::SUNRISE, self::formatToSecond($this->runs[0]->scheduledFor));
        self::assertSame(self::NEXT_SUNRISE, self::formatToSecond($task->getNextRunAt()));
    }

    public function testOffsetBeforeSunsetRunsEarlier(): void
    {
        $task = $this->scheduler->runAtSunset($this->record(...), SunOffset::before(Duration::minutes(30)));
        $this->goLive();

        self::assertSame('2026-10-05T15:45:22Z', self::formatToSecond($task->getNextRunAt()));
    }

    public function testOffsetAfterSunsetRunsLater(): void
    {
        $task = $this->scheduler->runAtSunset($this->record(...), SunOffset::after(Duration::minutes(15)));
        $this->goLive();

        self::assertSame('2026-10-05T16:30:22Z', self::formatToSecond($task->getNextRunAt()));
    }

    public function testAnySunEventCanBeScheduled(): void
    {
        $task = $this->scheduler->runAtSunEvent(SunEvent::CivilDusk, $this->record(...));
        $this->goLive();

        self::assertEquals($this->calendar->findNextEvent(SunEvent::CivilDusk), $task->getNextRunAt());
    }

    public function testSkippedSunsetsRunOnceAndCountMissed(): void
    {
        $this->scheduler->runAtSunset($this->record(...));
        $this->goLive();

        $this->timers->clock->skip(Duration::hours(60));
        $this->timers->delay(Duration::minutes(1));

        self::assertCount(1, $this->runs, 'Two sunsets passed during the step; one run happened.');
        self::assertSame(1, $this->runs[0]->missedOccurrences);
    }

    public function testAppSchedulerKeepsTheCalendar(): void
    {
        $task = $this->scheduler->forApp(new AppId('other'), new RecordingLogger())->runAtSunset($this->record(...));
        $this->scopes->activateScope(ResourceScope::forApp(new AppId('other')));
        $this->registry->startEntriesOf(ResourceScope::forApp(new AppId('other')));

        self::assertSame(self::SUNSET, self::formatToSecond($task->getNextRunAt()));
    }

    public function testUnknownLocationRefusesSunSchedule(): void
    {
        $scheduler = new WorkerScheduler($this->registry, new UnlocatedSunCalendar(), new RecordingLogger(), self::createScope());

        $this->assertThrowsReason(ScheduleError::SunLocationUnknown, fn(): ScheduledTask => $scheduler->runAtSunrise($this->record(...)));
        self::assertSame(0, $this->registry->countFor(self::createScope()));
    }

    private function goLive(): void
    {
        $this->scopes->activateScope(self::createScope());
        $this->registry->startEntriesOf(self::createScope());
    }

    private function record(ScheduledRun $run): void
    {
        $this->runs[] = $run;
    }

    private static function formatToSecond(?Instant $instant): string
    {
        self::assertNotNull($instant);

        return $instant->toDateTime(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function createScope(): ResourceScope
    {
        return ResourceScope::forApp(new AppId('demo'));
    }
}
