<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Control\Protocol\Status\WorkerStatus;

/** @extends ListCollection<WorkerStatus> */
final readonly class WorkerStatusCollection extends ListCollection
{
    /** @param iterable<WorkerStatus> $statuses */
    public static function fromStatuses(iterable $statuses): self
    {
        return self::fromList($statuses);
    }
}
