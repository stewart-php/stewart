<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Exception\WorkerError;
use Stewart\Runtime\Exception\WorkerException;

/** @extends ListCollection<WorkerSlot> */
final readonly class WorkerSlotCollection extends ListCollection
{
    /** @throws WorkerException */
    public function requireSlotById(string $workerId): WorkerSlot
    {
        return $this->findFirstWhere(static fn(WorkerSlot $slot): bool => (string) $slot->workerId === $workerId)
            ?? throw WorkerException::createForReason(WorkerError::Unknown, workerId: $workerId);
    }
}
