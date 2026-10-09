<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control;

use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Broker\ConnectionTracker;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Control\Assembler\BrokerStatsBuilder;
use Stewart\Runtime\Control\Assembler\ComponentStatusBuilder;
use Stewart\Runtime\Control\Assembler\DaemonInfoBuilder;
use Stewart\Runtime\Control\Assembler\DeployStatusBuilder;
use Stewart\Runtime\Control\Assembler\RegistrationInfoBuilder;
use Stewart\Runtime\Control\Assembler\StoreHealthBuilder;
use Stewart\Runtime\Control\Assembler\WorkerStatusBuilder;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;

final readonly class SnapshotAssembler
{
    public function __construct(
        private DaemonInfoBuilder $daemonInfo,
        private BrokerStatsBuilder $brokerStats,
        private WorkerStatusBuilder $workerStatuses,
        private RegistrationInfoBuilder $registrationInfos,
        private StoreHealthBuilder $storeHealth,
        private AppStatusBuilder $appStatuses,
        private DeployStatusBuilder $deployStatus,
        private ComponentStatusBuilder $componentStatus,
        private ConnectionTracker $connection,
        private Clock $clock,
    ) {}

    public function assembleSnapshot(): RuntimeSnapshot
    {
        return new RuntimeSnapshot(
            takenAt: $this->clock->getNow(),
            daemon: $this->daemonInfo->buildDaemonInfo(),
            connection: $this->connection->state,
            broker: $this->brokerStats->buildBrokerStats(),
            workers: $this->workerStatuses->buildWorkerStatuses()->listValues(),
            apps: $this->appStatuses->buildAppStatuses()->listValues(),
            subscriptions: $this->registrationInfos->buildRegistrationInfos()->listValues(),
            store: $this->storeHealth->buildStoreHealth(),
            deploy: $this->deployStatus->buildDeployStatus(),
            component: $this->componentStatus->buildComponentStatus(),
        );
    }
}
