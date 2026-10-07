<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Fixtures\State;

use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\CurrentStateReader;
use Stewart\Contracts\State\EntityState;

final class FixedStateReader implements CurrentStateReader
{
    private EntityStateCollection $states;

    public function __construct()
    {
        $this->states = EntityStateCollection::empty();
    }

    public function recordState(EntityState $state): void
    {
        $this->states = $this->states->withState($state);
    }

    public function readCurrentStates(): EntityStateCollection
    {
        return $this->states;
    }
}
