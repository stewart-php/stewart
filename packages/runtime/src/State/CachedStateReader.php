<?php

declare(strict_types=1);

namespace Stewart\Runtime\State;

use Stewart\Contracts\Registry\EntityFilter;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Support\State\CurrentStateReader;

final readonly class CachedStateReader implements CurrentStateReader
{
    public function __construct(
        private StateCache $states,
        private RegistryCache $registry,
        private Selector|EntityFilter $match,
    ) {}

    public function readCurrentStates(): EntityStateCollection
    {
        return $this->match instanceof EntityFilter
            ? $this->states->getAllStates()->filterByEntityFilter($this->match, $this->registry)
            : $this->states->filterBySelector($this->match);
    }
}
