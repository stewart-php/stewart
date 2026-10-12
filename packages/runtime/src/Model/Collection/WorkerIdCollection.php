<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Model\WorkerId;

/** @extends ListCollection<WorkerId> */
final readonly class WorkerIdCollection extends ListCollection
{
    /** @return list<int> */
    public function toInts(): array
    {
        return $this->mapToList(static fn(WorkerId $workerId): int => $workerId->value);
    }
}
