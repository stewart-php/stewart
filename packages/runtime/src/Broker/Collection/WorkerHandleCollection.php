<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Broker\WorkerHandle;

/** @extends ListCollection<WorkerHandle> */
final readonly class WorkerHandleCollection extends ListCollection
{
    /** @param iterable<WorkerHandle> $handles */
    public static function fromHandles(iterable $handles): self
    {
        return self::fromList($handles);
    }
}
