<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\BrokerSubscription;
use Stewart\Runtime\Broker\ConnectionTracker;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Control\Assembler\BrokerStatsBuilder;
use Stewart\Runtime\Control\Assembler\DaemonInfoBuilder;
use Stewart\Runtime\Control\Assembler\RegistrationInfoBuilder;
use Stewart\Runtime\Control\Assembler\StoreHealthBuilder;
use Stewart\Runtime\Control\Assembler\WorkerStatusBuilder;
use Stewart\Runtime\Control\Protocol\Status\DaemonInfo;
use Stewart\Runtime\Control\Protocol\Status\RegistrationInfo;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Control\SnapshotAssembler;
use Stewart\Runtime\Ipc\Message\AppActivityReport;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Lifecycle\ConnectionPhase;
use Stewart\Runtime\Lifecycle\WorkerPhase;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Control\BrokerStateFixture;

#[CoversClass(SnapshotAssembler::class)]
#[CoversClass(DaemonInfoBuilder::class)]
#[CoversClass(BrokerStatsBuilder::class)]
#[CoversClass(RegistrationInfoBuilder::class)]
#[CoversClass(RuntimeSnapshot::class)]
#[CoversClass(DaemonInfo::class)]
#[CoversClass(RegistrationInfo::class)]
final class SnapshotAssemblerTest extends TestCase
{
    private BrokerStateFixture $broker;

    private DaemonStartTime $startTime;

    protected function setUp(): void
    {
        $this->broker = new BrokerStateFixture();
        $this->startTime = new DaemonStartTime($this->broker->timers->clock);
        $this->startTime->recordStart();
    }

    protected function tearDown(): void
    {
        $this->broker->stopEverything();
    }

    public function testJoinsBrokerAndWorkerReports(): void
    {
        $this->broker->startWorker(0);
        $this->broker->startWorker(1);

        $this->broker->registry->add(new BrokerSubscription(new SubscriptionId('w0:0'), new WorkerId(0), ResourceScope::forApp(new AppId('demo')), SubscriptionKind::StateChange, Selector::fromSpec('light.hall')));
        $this->broker->registry->add(new BrokerSubscription(new SubscriptionId('w1:0'), new WorkerId(1), ResourceScope::forApp(new AppId('echo')), SubscriptionKind::Topic, Selector::fromSpec('demo.*')));

        $handle = $this->broker->getLiveHandleOf(0);
        $this->broker->serviceCalls->forward($handle, new ServiceCallRequest(new CorrelationId('0:1'), ResourceScope::forApp(new AppId('demo')), 'light', 'turn_on', [], null, false));
        $this->broker->metrics->recordActivityReports($handle, new Pong(1, Duration::microseconds(300), 5_000_000, [new AppActivityReport(ResourceScope::forApp(new AppId('demo')), AppState::Running, 1, 0, 3, 0, 0, 0, 0)]));
        $this->broker->crashWorker(1);

        $snapshot = $this->createAssembler()->assembleSnapshot();

        self::assertSame('0.1.0-test', $snapshot->daemon->version);
        self::assertEquals($this->startTime->getStartedAt(), $snapshot->daemon->startedAt);
        self::assertSame('Europe/Budapest', $snapshot->daemon->timeZone);
        self::assertSame('2026.8.1', $snapshot->daemon->haVersion);
        self::assertSame(1, $snapshot->daemon->entities);
        self::assertGreaterThan(0, $snapshot->daemon->pid);
        self::assertSame(ConnectionPhase::Connected, $snapshot->connection->phase);

        self::assertSame(2, $snapshot->broker->workers);
        self::assertSame(1, $snapshot->broker->liveWorkers);
        self::assertSame(1, $snapshot->broker->inFlightServiceCalls);
        self::assertSame(2, $snapshot->broker->routing->subscriptions);

        [$live, $down] = $snapshot->workers;
        self::assertSame(WorkerPhase::Live, $live->phase);
        self::assertSame(1, $live->inFlightServiceCalls);
        self::assertSame(WorkerPhase::RestartScheduled, $down->phase);
        self::assertSame(['echo'], $down->appIds);
        self::assertSame(0, $down->inFlightServiceCalls);

        [$demo, $echo] = $snapshot->apps;
        self::assertSame(['demo', 0, AppState::Running, 3], [$demo->id, $demo->workerId, $demo->state, $demo->counters->delivered]);
        self::assertSame(['echo', 1, null], [$echo->id, $echo->workerId, $echo->state], 'An app that never reported activity is listed with an unknown state.');

        self::assertSame(['w0:0', 'w1:0'], array_map(static fn(RegistrationInfo $r): string => $r->subscriptionId, $snapshot->subscriptions));
        self::assertTrue($snapshot->subscriptions[0]->exact);
        self::assertFalse($snapshot->subscriptions[1]->exact);
        self::assertSame('glob:demo.*', $snapshot->subscriptions[1]->selector);
        self::assertNull($snapshot->store, 'Without persistence there is no store to report.');
    }

    private function createAssembler(): SnapshotAssembler
    {
        $broker = $this->broker;
        $connection = new ConnectionTracker($broker->timers->clock);
        $connection->markConnected();

        return new SnapshotAssembler(
            daemonInfo: new DaemonInfoBuilder($broker->session, '0.1.0-test', $this->startTime),
            brokerStats: new BrokerStatsBuilder($broker->pools->slots, $broker->serviceCalls, $broker->registry),
            workerStatuses: new WorkerStatusBuilder($broker->pools->slots, $broker->pools->watchdog, $broker->pools->restartPolicy, $broker->serviceCalls),
            registrationInfos: new RegistrationInfoBuilder($broker->registry),
            storeHealth: new StoreHealthBuilder($broker->pools->slots, storeConfigured: false),
            appStatuses: new AppStatusBuilder($broker->metrics),
            connection: $connection,
            clock: $broker->timers->clock,
        );
    }
}
