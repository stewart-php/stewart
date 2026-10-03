<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;

/** @extends ListCollection<AppStatus> */
final readonly class AppStatusCollection extends ListCollection
{
    /** @param iterable<AppStatus> $statuses */
    public static function fromStatuses(iterable $statuses): self
    {
        return self::fromList($statuses);
    }
}
