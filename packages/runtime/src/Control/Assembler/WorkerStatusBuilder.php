<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Assembler;

use Stewart\Runtime\Broker\ServiceCallProxy;
use Stewart\Runtime\Broker\WorkerRestartPolicy;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Broker\WorkerSlotState;
use Stewart\Runtime\Broker\WorkerWatchdog;
use Stewart\Runtime\Control\Protocol\Status\Collection\WorkerStatusCollection;
use Stewart\Runtime\Control\Protocol\Status\WorkerStatus;
use Stewart\Runtime\Lifecycle\WorkerPhase;

final readonly class WorkerStatusBuilder
{
    public function __construct(
        private WorkerSlotRegistry $slots,
        private WorkerWatchdog $watchdog,
        private WorkerRestartPolicy $restartPolicy,
        private ServiceCallProxy $serviceCalls,
    ) {}

    public function buildWorkerStatuses(): WorkerStatusCollection
    {
        return WorkerStatusCollection::fromStatuses($this->slots->listSlotStates()->mapToList($this->buildWorkerStatus(...)));
    }

    private function buildWorkerStatus(WorkerSlotState $state): WorkerStatus
    {
        $workerId = $state->slot->workerId;
        $live = $state->phase === WorkerPhase::Live ? $state->handle : null;
        $pong = $live?->lastPong;

        return new WorkerStatus(
            workerId: $workerId->value,
            phase: $state->phase,
            appIds: $state->slot->listAppIds()->toStrings(),
            pid: $live?->getPid(),
            ready: $live?->isReady() ?? false,
            missedProbes: $live === null ? 0 : $this->watchdog->countMissedProbes($live),
            loopLag: $pong?->loopLag,
            memoryBytes: $pong?->memoryBytes,
            lastPongAt: $live?->lastPongAt,
            restartsInWindow: $this->restartPolicy->countRestartsInWindow($workerId),
            restartDueAt: $state->restartDueAt,
            outbox: $state->getOutboxStatus(),
            inFlightServiceCalls: $live === null ? 0 : $this->serviceCalls->countInFlightCallsFor($live),
        );
    }
}
