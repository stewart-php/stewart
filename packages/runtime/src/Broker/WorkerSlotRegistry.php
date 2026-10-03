<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Runtime\Broker\Collection\WorkerHandleCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotStateCollection;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Lifecycle\WorkerPhase;
use Stewart\Runtime\Model\WorkerId;

final class WorkerSlotRegistry
{
    /** @var array<int, WorkerSlotState> */
    private array $states = [];

    public function findOrCreateSlotState(WorkerSlot $slot, WorkerPoolListener $listener): WorkerSlotState
    {
        return $this->states[$slot->workerId->value] ??= new WorkerSlotState($slot, $listener);
    }

    public function findSlotState(WorkerId $workerId): ?WorkerSlotState
    {
        return $this->states[$workerId->value] ?? null;
    }

    public function findHandleForWorker(WorkerId $workerId): ?WorkerHandle
    {
        return $this->findSlotState($workerId)?->handle;
    }

    public function listSlotStates(): WorkerSlotStateCollection
    {
        return WorkerSlotStateCollection::fromStatesInWorkerOrder($this->states);
    }

    public function listLiveHandles(): WorkerHandleCollection
    {
        $live = [];

        foreach ($this->states as $state) {
            $handle = $state->handle;

            if ($handle !== null && !$handle->isTerminated()) {
                $live[] = $handle;
            }
        }

        return WorkerHandleCollection::fromHandles($live);
    }

    public function broadcast(BrokerMessage $message): void
    {
        foreach ($this->listLiveHandles() as $handle) {
            $handle->send($message);
        }
    }

    public function countSpawnedSlots(): int
    {
        return \count(array_filter($this->states, static fn(WorkerSlotState $state): bool => $state->handle !== null));
    }

    public function countLiveWorkers(): int
    {
        return $this->listLiveHandles()->count();
    }

    public function countPendingRestarts(): int
    {
        return \count(array_filter($this->states, static fn(WorkerSlotState $state): bool => \in_array(
            $state->phase,
            [WorkerPhase::Spawning, WorkerPhase::RestartScheduled],
            true,
        )));
    }
}
