<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Broker\WorkerSlotState;

/** @extends ListCollection<WorkerSlotState> */
final readonly class WorkerSlotStateCollection extends ListCollection
{
    /** @param iterable<WorkerSlotState> $states */
    public static function fromStatesInWorkerOrder(iterable $states): self
    {
        return self::fromList($states)->sortedBy(static fn(WorkerSlotState $a, WorkerSlotState $b): int => $a->slot->workerId->value <=> $b->slot->workerId->value);
    }
}
