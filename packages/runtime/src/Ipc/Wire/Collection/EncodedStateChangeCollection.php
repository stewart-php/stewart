<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Ipc\Wire\EncodedStateChange;

/** @extends ListCollection<EncodedStateChange> */
final readonly class EncodedStateChangeCollection extends ListCollection
{
    /** @param iterable<EncodedStateChange> $changes */
    public static function fromChanges(iterable $changes): self
    {
        return self::fromList($changes);
    }
}
