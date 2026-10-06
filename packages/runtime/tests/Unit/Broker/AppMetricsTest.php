<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\AppPause;
use Stewart\Runtime\App\AppPauseSource;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\AppRunningTotals;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\ServiceCallStatsRecorder;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Ipc\Message\AppActivityReport;
use Stewart\Runtime\Ipc\Message\AppFailed;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Runtime\Time\SystemClock;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(AppMetrics::class)]
#[CoversClass(AppStatusBuilder::class)]
#[CoversClass(AppRunningTotals::class)]
#[CoversClass(ServiceCallStatsRecorder::class)]
final class AppMetricsTest extends TestCase
{
    private AppMetrics $metrics;

    private AppPauseRegistry $pausedApps;

    protected function setUp(): void
    {
        $this->metrics = new AppMetrics(
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])), new WorkerSlot(new WorkerId(1), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('echo'), Demo::class)]))]),
            SystemClock::inUtc(),
        );
        $this->pausedApps = new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]), new VirtualClock());
    }

    public function testEveryPlacedAppIsListedBeforeItReportsActivity(): void
    {
        self::assertSame(
            [['demo', Demo::class, 0, null], ['echo', Demo::class, 1, null]],
            array_map(static fn(AppStatus $app): array => [$app->id, $app->class, $app->workerId, $app->state], $this->listAppStatuses()),
        );
    }

    public function testSharedScopeIsTalliedOncePerWorker(): void
    {
        $this->metrics->recordActivityReports(self::createHandle(0), new Pong(1, Duration::zero(), 0, [new AppActivityReport(ResourceScope::shared(), AppState::Running, 1, 0, 2, 0, 0, 0, 0, 0)]));
        $this->metrics->recordActivityReports(self::createHandle(1), new Pong(1, Duration::zero(), 0, [new AppActivityReport(ResourceScope::shared(), AppState::Running, 0, 0, 5, 0, 0, 0, 0, 0)]));

        $shared = array_values(array_filter($this->listAppStatuses(), static fn(AppStatus $app): bool => $app->id === '@shared'));

        self::assertSame([[0, 2], [1, 5]], array_map(static fn(AppStatus $app): array => [$app->workerId, $app->counters->delivered], $shared));
    }

    public function testFailureCountComesFromActivityReports(): void
    {
        $handle = self::createHandle(0);
        $this->metrics->recordActivityReports($handle, new Pong(1, Duration::zero(), 0, [new AppActivityReport(ResourceScope::forApp(new AppId('demo')), AppState::Running, 1, 0, 0, 0, 0, 0, 2, 0)]));
        $this->metrics->recordLastFailure(new WorkerId(0), new AppFailed(ResourceScope::forApp(new AppId('demo')), AppFailurePhase::Handler, 'RuntimeException', 'boom', '', 'subscription w0:1', 100, null));
        $this->metrics->recordActivityReports($handle, new Pong(2, Duration::zero(), 0, [new AppActivityReport(ResourceScope::forApp(new AppId('demo')), AppState::Running, 1, 0, 0, 0, 0, 0, 150, 0)]));

        $demo = $this->listAppStatuses()[0];

        self::assertSame(150, $demo->counters->failures);
        self::assertSame('boom', $demo->lastFailure?->message);
    }

    public function testPausedAppReportsSuppressedDelta(): void
    {
        $handle = self::createHandle(0);
        $this->pausedApps->pauseApp(new AppPause(new AppId('demo'), Instant::fromEpochMicroseconds(0), AppPauseSource::Control));
        $this->metrics->recordActivityReports($handle, new Pong(1, Duration::zero(), 0, [new AppActivityReport(ResourceScope::forApp(new AppId('demo')), AppState::Running, 1, 0, 0, 0, 0, 0, 0, 3)]));
        $this->metrics->recordActivityReports($handle, new Pong(2, Duration::zero(), 0, [new AppActivityReport(ResourceScope::forApp(new AppId('demo')), AppState::Running, 1, 0, 0, 0, 0, 0, 0, 7)]));

        [$demo, $echo] = $this->listAppStatuses();

        self::assertSame([true, 7], [$demo->paused, $demo->counters->suppressed]);
        self::assertFalse($echo->paused);
    }

    public function testLatencyOnABoundCountsInThatBucket(): void
    {
        $this->metrics->recordFinishedCall(new WorkerId(0), ResourceScope::forApp(new AppId('demo')), ServiceCallOutcome::Succeeded, Duration::milliseconds(5));
        $this->metrics->recordFinishedCall(new WorkerId(0), ResourceScope::forApp(new AppId('demo')), ServiceCallOutcome::Succeeded, Duration::microseconds(5_001));
        $this->metrics->recordFinishedCall(new WorkerId(0), ResourceScope::forApp(new AppId('demo')), ServiceCallOutcome::Succeeded, Duration::seconds(6));

        $latency = $this->listAppStatuses()[0]->serviceCalls[0]->latency;

        self::assertNotNull($latency);
        self::assertSame([1, 2], \array_slice($latency->cumulativeCounts, 0, 2));
        self::assertSame(2, $latency->cumulativeCounts[9], 'Six seconds is past the 5000 ms bound.');
        self::assertSame(3, $latency->count);
    }

    /** @return list<AppStatus> */
    private function listAppStatuses(): array
    {
        return new AppStatusBuilder($this->metrics, $this->pausedApps)->buildAppStatuses()->listValues();
    }

    private static function createHandle(int $workerId): WorkerHandle
    {
        return new WorkerHandle(new WorkerId($workerId), new FakeWorkerProcess(), new WorkerSlot(new WorkerId($workerId), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])), new NullLogger(), new OutboxLimits(10, 256));
    }
}
