<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\EntityFilter;
use Stewart\Contracts\Selector\Collection\SelectorCollection;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\CurrentStateReader;

final readonly class RecordedStateReader implements CurrentStateReader
{
    public function __construct(
        private RecordingHaContext $ha,
        private string|EntityId|Selector|SelectorCollection|EntityFilter $selector,
    ) {}

    public function readCurrentStates(): EntityStateCollection
    {
        return $this->ha->listStates($this->selector);
    }
}
