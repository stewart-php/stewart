<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Testing\HaContext\WatchedTrigger;

/** @extends ListCollection<WatchedTrigger> */
final readonly class WatchedTriggerCollection extends ListCollection
{
    public function withWatchedTrigger(WatchedTrigger $trigger): self
    {
        return $this->withAppendedElement($trigger);
    }
}
