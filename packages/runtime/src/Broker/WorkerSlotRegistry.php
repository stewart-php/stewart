<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
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

    /** @return list<WorkerSlotState> */
    public function listSlotStates(): array
    {
        $states = $this->states;
        ksort($states);

        return array_values($states);
    }

    /** @return list<WorkerHandle> */
    public function listLiveHandles(): array
    {
        $live = [];

        foreach ($this->states as $state) {
            $handle = $state->handle;

            if ($handle !== null && !$handle->isTerminated()) {
                $live[] = $handle;
            }
        }

        return $live;
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
        return \count($this->listLiveHandles());
    }
}
