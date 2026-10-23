<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control;

use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Broker\Component\ComponentTracker;
use Stewart\Runtime\Broker\ConnectionTracker;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\Deploy\DeployState;
use Stewart\Runtime\Broker\GeneratedCodeDrift;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\HaSession;
use Stewart\Runtime\Broker\Reload\ReloadState;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Control\Assembler\MqttStatusBuilder;
use Stewart\Runtime\Control\Assembler\ScheduleStatusBuilder;
use Stewart\Runtime\Control\Assembler\StoreHealthBuilder;
use Stewart\Runtime\Control\Assembler\SubscriptionInfoBuilder;
use Stewart\Runtime\Control\Assembler\WorkerStatusBuilder;
use Stewart\Runtime\Control\Protocol\Status\BrokerStats;
use Stewart\Runtime\Control\Protocol\Status\ComponentStatus;
use Stewart\Runtime\Control\Protocol\Status\DaemonInfo;
use Stewart\Runtime\Control\Protocol\Status\DeployFailure;
use Stewart\Runtime\Control\Protocol\Status\DeployStatus;
use Stewart\Runtime\Control\Protocol\Status\ReloadAttempt;
use Stewart\Runtime\Control\Protocol\Status\ReloadStatus;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Health\ProcessMemory;
use Stewart\Runtime\Lifecycle\ReloadOutcome;
use Stewart\Runtime\Model\StewartVersion;

final readonly class SnapshotAssembler implements SnapshotSource
{
    public function __construct(
        private HaSession $session,
        private StewartVersion $stewartVersion,
        private DaemonStartTime $startTime,
        private ConnectionTracker $connection,
        private WorkerSlotRegistry $slots,
        private HaCallSlots $callSlots,
        private SubscriptionRegistry $registry,
        private DeployState $deployState,
        private ReloadState $reloadState,
        private ComponentTracker $componentTracker,
        private ExposeConfig $expose,
        private WorkerStatusBuilder $workerStatuses,
        private AppStatusBuilder $appStatuses,
        private StoreHealthBuilder $storeHealth,
        private SubscriptionInfoBuilder $subscriptionInfos,
        private ScheduleStatusBuilder $scheduleStatuses,
        private MqttStatusBuilder $mqttStatus,
        private GeneratedCodeDrift $drift,
        private ProcessMemory $processMemory,
        private Clock $clock,
    ) {}

    public function takeSnapshot(): RuntimeSnapshot
    {
        return new RuntimeSnapshot(
            takenAt: $this->clock->getNow(),
            daemon: $this->buildDaemonInfo(),
            connection: $this->connection->state,
            broker: $this->buildBrokerStats(),
            workers: $this->workerStatuses->buildWorkerStatuses(),
            apps: $this->appStatuses->buildAppStatuses(),
            subscriptions: $this->subscriptionInfos->buildSubscriptionInfos(),
            store: $this->storeHealth->buildStoreHealth(),
            deploy: $this->buildDeployStatus(),
            component: $this->buildComponentStatus(),
            reload: $this->buildReloadStatus(),
            schedules: $this->scheduleStatuses->buildScheduleStatuses(),
            mqtt: $this->mqttStatus->buildMqttStatus(),
            generated: $this->drift->status,
        );
    }

    private function buildDaemonInfo(): DaemonInfo
    {
        return new DaemonInfo(
            pid: getmypid() ?: 0,
            startedAt: $this->startTime->getStartedAt(),
            memoryBytes: $this->processMemory->measureResidentBytes(),
            version: $this->stewartVersion->value,
            timeZone: $this->session->getTimeZone()->getName(),
            entities: $this->session->countEntities(),
            haVersion: $this->session->getHaVersion(),
        );
    }

    private function buildBrokerStats(): BrokerStats
    {
        return new BrokerStats(
            workers: $this->slots->countSpawnedSlots(),
            liveWorkers: $this->slots->countLiveWorkers(),
            inFlightHaCalls: $this->callSlots->inFlight,
            refusedHaCalls: $this->callSlots->refusedCalls,
            routing: $this->registry->getRoutingStats(),
        );
    }

    private function buildDeployStatus(): ?DeployStatus
    {
        $commit = $this->deployState->findRunningCommit();

        if ($commit === null) {
            return null;
        }

        $failure = $this->deployState->findLastFailure();

        return new DeployStatus(
            commit: $commit->value,
            lastPolledAt: $this->deployState->findLastPolledAt(),
            failures: $this->deployState->countFailures(),
            lastFailure: $failure === null ? null : new DeployFailure($failure->commit->value, $failure->reason, $failure->failedAt),
        );
    }

    private function buildReloadStatus(): ReloadStatus
    {
        $last = $this->reloadState->findLastResult();

        return new ReloadStatus(
            underway: $this->reloadState->isUnderway(),
            applied: $this->reloadState->countResults(ReloadOutcome::Applied),
            partlyApplied: $this->reloadState->countResults(ReloadOutcome::PartlyApplied),
            failed: $this->reloadState->countResults(ReloadOutcome::Failed),
            last: $last === null ? null : new ReloadAttempt($last->outcome, $last->finishedAt, $last->error, $last->settingsNeedingRestart),
        );
    }

    private function buildComponentStatus(): ComponentStatus
    {
        $detection = $this->componentTracker->detection;

        return new ComponentStatus(
            state: $detection->state,
            since: $detection->since,
            instance: $this->expose->instance->value,
            version: $detection->version?->componentVersion,
            protocol: $detection->version?->protocol,
        );
    }
}
