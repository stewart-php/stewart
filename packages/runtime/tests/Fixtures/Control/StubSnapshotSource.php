<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Control;

use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Control\Protocol\Status\AppCounters;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Control\Protocol\Status\BrokerStats;
use Stewart\Runtime\Control\Protocol\Status\ConnectionState;
use Stewart\Runtime\Control\Protocol\Status\DaemonInfo;
use Stewart\Runtime\Control\Protocol\Status\FailureReport;
use Stewart\Runtime\Control\Protocol\Status\LatencyHistogram;
use Stewart\Runtime\Control\Protocol\Status\OutboxStatus;
use Stewart\Runtime\Control\Protocol\Status\RegistrationInfo;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Control\Protocol\Status\ServiceCallStats;
use Stewart\Runtime\Control\Protocol\Status\WorkerStatus;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Lifecycle\ConnectionPhase;
use Stewart\Runtime\Lifecycle\WorkerPhase;
use Stewart\Runtime\Model\RoutingStats;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Store\StoreHealth;

final class StubSnapshotSource
{
    public int $taken = 0;

    public function __construct(
        private readonly string $daemonVersion = 'stub',
        private readonly ConnectionPhase $connectionPhase = ConnectionPhase::Connected,
        private readonly WorkerPhase $workerPhase = WorkerPhase::Live,
    ) {}

    public function takeSnapshot(): RuntimeSnapshot
    {
        ++$this->taken;
        $startedAt = Instant::fromIso('2026-09-26T10:00:00Z');
        $takenAt = Instant::fromIso('2026-09-26T11:00:00Z');

        return new RuntimeSnapshot(
            takenAt: $takenAt,
            daemon: new DaemonInfo(1, $startedAt, 52_428_800, $this->daemonVersion, 'UTC', 120, '2026.9.1'),
            connection: new ConnectionState($this->connectionPhase, $startedAt, 1, Duration::seconds(4)),
            broker: new BrokerStats(1, 1, 0, 2, new RoutingStats(3, 1, 5, 40, 5)),
            workers: [new WorkerStatus(
                workerId: 0,
                phase: $this->workerPhase,
                appIds: ['porch'],
                pid: 42,
                ready: true,
                loopLag: Duration::milliseconds(3),
                memoryBytes: 20_971_520,
                lastPongAt: Instant::fromIso('2026-09-26T10:59:55Z'),
                outbox: new OutboxStatus(0, 0, 7, 90, 12),
            )],
            apps: [new AppStatus(
                id: 'porch',
                class: 'App\\Porch',
                workerId: 0,
                state: AppState::Running,
                reportedAt: Instant::fromIso('2026-09-26T10:59:55Z'),
                subscriptions: 2,
                schedules: 1,
                counters: new AppCounters(delivered: 30, subscriptionDropped: 1, scheduleRuns: 4, publishes: 2, failures: 1),
                serviceCalls: [
                    new ServiceCallStats(ServiceCallOutcome::Succeeded, 10, new LatencyHistogram([5, 10, 25], [2, 9, 10], 10, Duration::milliseconds(80))),
                    new ServiceCallStats(ServiceCallOutcome::Refused, 2, null),
                ],
                lastFailure: new FailureReport(AppFailurePhase::Handler, 'RuntimeException', 'boom', 'subscription w0:1', 'entity_not_found', Instant::fromIso('2026-09-26T10:30:00Z')),
            )],
            subscriptions: [new RegistrationInfo('w0:0', 0, 'porch', SubscriptionKind::Event, 'doorbell', true)],
            store: new StoreHealth(true, 'timed out', Instant::fromIso('2026-09-26T10:45:00Z')),
        );
    }
}
