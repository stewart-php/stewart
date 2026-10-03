<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Model\WorkerId;

/** @extends ListCollection<WorkerId> */
final readonly class WorkerIdCollection extends ListCollection
{
    /** @param iterable<WorkerId> $workerIds */
    public static function fromIds(iterable $workerIds): self
    {
        return self::fromList($workerIds);
    }

    /** @return list<int> */
    public function toInts(): array
    {
        return $this->mapToList(static fn(WorkerId $workerId): int => $workerId->value);
    }
}
