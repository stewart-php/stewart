<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Control;

use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Control\Protocol\Status\AppCounters;
use Stewart\Runtime\Control\Protocol\Status\AppPauseStatus;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Control\Protocol\Status\BrokerStats;
use Stewart\Runtime\Control\Protocol\Status\ComponentStatus;
use Stewart\Runtime\Control\Protocol\Status\ConnectionState;
use Stewart\Runtime\Control\Protocol\Status\DaemonInfo;
use Stewart\Runtime\Control\Protocol\Status\DeployFailure;
use Stewart\Runtime\Control\Protocol\Status\DeployStatus;
use Stewart\Runtime\Control\Protocol\Status\FailureReport;
use Stewart\Runtime\Control\Protocol\Status\LatencyHistogram;
use Stewart\Runtime\Control\Protocol\Status\MqttDropCount;
use Stewart\Runtime\Control\Protocol\Status\MqttStatus;
use Stewart\Runtime\Control\Protocol\Status\OutboxStatus;
use Stewart\Runtime\Control\Protocol\Status\RegistrationInfo;
use Stewart\Runtime\Control\Protocol\Status\ReloadAttempt;
use Stewart\Runtime\Control\Protocol\Status\ReloadStatus;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Control\Protocol\Status\ScheduleStatus;
use Stewart\Runtime\Control\Protocol\Status\ServiceCallStats;
use Stewart\Runtime\Control\Protocol\Status\WorkerStatus;
use Stewart\Runtime\Control\SnapshotSource;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Runtime\Lifecycle\ConnectionPhase;
use Stewart\Runtime\Lifecycle\ReloadOutcome;
use Stewart\Runtime\Lifecycle\WorkerPhase;
use Stewart\Runtime\Model\MqttDropReason;
use Stewart\Runtime\Model\RoutingStats;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Store\StoreHealth;

final class StubSnapshotSource implements SnapshotSource
{
    public int $taken = 0;

    /** @param list<WorkerPhase> $workerPhases */
    public function __construct(
        private readonly string $daemonVersion = 'stub',
        private readonly ConnectionPhase $connectionPhase = ConnectionPhase::Connected,
        private readonly array $workerPhases = [WorkerPhase::Live],
        private readonly bool $workersReady = true,
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
            workers: array_map($this->createWorkerStatus(...), array_keys($this->workerPhases), $this->workerPhases),
            apps: [new AppStatus(
                id: 'porch',
                class: 'App\\Porch',
                workerId: 0,
                state: AppState::Running,
                pause: new AppPauseStatus(Instant::fromIso('2026-09-26T10:58:00Z'), AppPauseSource::Control),
                configPauseOverride: null,
                reportedAt: Instant::fromIso('2026-09-26T10:59:55Z'),
                subscriptions: 2,
                schedules: 1,
                exposedEntities: 3,
                counters: new AppCounters(delivered: 30, subscriptionDropped: 1, scheduleRuns: 4, publishes: 2, failures: 1, suppressed: 6, handled: 12),
                serviceCalls: [
                    new ServiceCallStats(ServiceCallOutcome::Succeeded, 10, new LatencyHistogram([5, 10, 25], [2, 9, 10], 10, Duration::milliseconds(80))),
                    new ServiceCallStats(ServiceCallOutcome::Refused, 2, null),
                ],
                lastFailure: new FailureReport(AppFailurePhase::Handler, 'RuntimeException', 'boom', 'subscription w0:1', 'entity_not_found', Instant::fromIso('2026-09-26T10:30:00Z')),
                lastHandledAt: Instant::fromIso('2026-09-26T10:57:00Z'),
                nextRunAt: Instant::fromIso('2026-09-26T19:00:00Z'),
            )],
            subscriptions: [
                new RegistrationInfo('w0:0', 0, 'porch', SubscriptionKind::Event, 'doorbell', true),
                new RegistrationInfo('w0:1', 0, 'porch', SubscriptionKind::StateChange, 'exact:binary_sensor.porch_motion', true, 18, 12, 1, Instant::fromIso('2026-09-26T10:58:30Z'), Instant::fromIso('2026-09-26T10:57:00Z')),
            ],
            store: new StoreHealth(true, 'timed out', Instant::fromIso('2026-09-26T10:45:00Z')),
            deploy: new DeployStatus(
                commit: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                lastPolledAt: Instant::fromIso('2026-09-26T10:59:30Z'),
                failures: 1,
                lastFailure: new DeployFailure('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'apps/Porch.php failed to load: syntax error', Instant::fromIso('2026-09-26T10:50:00Z')),
            ),
            component: new ComponentStatus(ComponentState::Active, $startedAt, 'default', '0.9.0', 1),
            reload: new ReloadStatus(
                applied: 2,
                partlyApplied: 1,
                last: new ReloadAttempt(ReloadOutcome::PartlyApplied, Instant::fromIso('2026-09-26T10:55:00Z'), null, ['mqtt']),
            ),
            schedules: [new ScheduleStatus('porch', 0, 'w0:2', 'every day at 19:00', Instant::fromIso('2026-09-26T19:00:00Z'), Instant::fromIso('2026-09-25T19:00:00Z'))],
            mqtt: new MqttStatus(
                phase: ConnectionPhase::Connected,
                since: Instant::fromIso('2026-09-26T10:50:00Z'),
                reconnects: 1,
                lastOutage: Duration::seconds(3),
                lastError: null,
                dropped: array_map(static fn(MqttDropReason $reason): MqttDropCount => new MqttDropCount($reason, $reason === MqttDropReason::Disconnected ? 2 : 0), MqttDropReason::cases()),
                outboundQueued: 1,
            ),
        );
    }

    private function createWorkerStatus(int $workerId, WorkerPhase $phase): WorkerStatus
    {
        return new WorkerStatus(
            workerId: $workerId,
            phase: $phase,
            appIds: $workerId === 0 ? ['porch'] : [],
            pid: 42 + $workerId,
            ready: $phase === WorkerPhase::Live && $this->workersReady,
            loopLag: Duration::milliseconds(3),
            memoryBytes: 20_971_520,
            lastPongAt: Instant::fromIso('2026-09-26T10:59:55Z'),
            outbox: new OutboxStatus(0, 0, 7, 90, 12),
            restarts: 3,
            quarantines: 1,
        );
    }
}
