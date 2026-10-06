<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppMetrics;
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

#[CoversClass(AppMetrics::class)]
#[CoversClass(AppStatusBuilder::class)]
#[CoversClass(AppRunningTotals::class)]
#[CoversClass(ServiceCallStatsRecorder::class)]
final class AppMetricsTest extends TestCase
{
    private AppMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new AppMetrics(
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])), new WorkerSlot(new WorkerId(1), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('echo'), Demo::class)]))]),
            SystemClock::inUtc(),
        );
    }

    public function testEveryPlacedAppIsListedBeforeItReportsActivity(): void
    {
        self::assertSame(
            [['demo', Demo::class, 0, null], ['echo', Demo::class, 1, null]],
            array_map(static fn(AppStatus $app): array => [$app->id, $app->class, $app->workerId, $app->state], new AppStatusBuilder($this->metrics)->buildAppStatuses()->listValues()),
        );
    }

    public function testSharedScopeIsTalliedOncePerWorker(): void
    {
        $this->metrics->recordActivityReports(self::createHandle(0), new Pong(1, Duration::zero(), 0, [new AppActivityReport(ResourceScope::shared(), AppState::Running, 1, 0, 2, 0, 0, 0, 0, 0)]));
        $this->metrics->recordActivityReports(self::createHandle(1), new Pong(1, Duration::zero(), 0, [new AppActivityReport(ResourceScope::shared(), AppState::Running, 0, 0, 5, 0, 0, 0, 0, 0)]));

        $shared = array_values(array_filter(new AppStatusBuilder($this->metrics)->buildAppStatuses()->listValues(), static fn(AppStatus $app): bool => $app->id === '@shared'));

        self::assertSame([[0, 2], [1, 5]], array_map(static fn(AppStatus $app): array => [$app->workerId, $app->counters->delivered], $shared));
    }

    public function testFailureCountComesFromActivityReports(): void
    {
        $handle = self::createHandle(0);
        $this->metrics->recordActivityReports($handle, new Pong(1, Duration::zero(), 0, [new AppActivityReport(ResourceScope::forApp(new AppId('demo')), AppState::Running, 1, 0, 0, 0, 0, 0, 2, 0)]));
        $this->metrics->recordLastFailure(new WorkerId(0), new AppFailed(ResourceScope::forApp(new AppId('demo')), AppFailurePhase::Handler, 'RuntimeException', 'boom', '', 'subscription w0:1', 100, null));
        $this->metrics->recordActivityReports($handle, new Pong(2, Duration::zero(), 0, [new AppActivityReport(ResourceScope::forApp(new AppId('demo')), AppState::Running, 1, 0, 0, 0, 0, 0, 150, 0)]));

        $demo = new AppStatusBuilder($this->metrics)->buildAppStatuses()->listValues()[0];

        self::assertSame(150, $demo->counters->failures);
        self::assertSame('boom', $demo->lastFailure?->message);
    }

    public function testLatencyOnABoundCountsInThatBucket(): void
    {
        $this->metrics->recordFinishedCall(new WorkerId(0), ResourceScope::forApp(new AppId('demo')), ServiceCallOutcome::Succeeded, Duration::milliseconds(5));
        $this->metrics->recordFinishedCall(new WorkerId(0), ResourceScope::forApp(new AppId('demo')), ServiceCallOutcome::Succeeded, Duration::microseconds(5_001));
        $this->metrics->recordFinishedCall(new WorkerId(0), ResourceScope::forApp(new AppId('demo')), ServiceCallOutcome::Succeeded, Duration::seconds(6));

        $latency = new AppStatusBuilder($this->metrics)->buildAppStatuses()->listValues()[0]->serviceCalls[0]->latency;

        self::assertNotNull($latency);
        self::assertSame([1, 2], \array_slice($latency->cumulativeCounts, 0, 2));
        self::assertSame(2, $latency->cumulativeCounts[9], 'Six seconds is past the 5000 ms bound.');
        self::assertSame(3, $latency->count);
    }

    private static function createHandle(int $workerId): WorkerHandle
    {
        return new WorkerHandle(new WorkerId($workerId), new FakeWorkerProcess(), new WorkerSlot(new WorkerId($workerId), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])), new NullLogger(), new OutboxLimits(10, 256));
    }
}
