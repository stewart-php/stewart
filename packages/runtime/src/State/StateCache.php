<?php

declare(strict_types=1);

namespace Stewart\Runtime\State;

use Stewart\Contracts\Entity\Collection\EntityIdCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;

final class StateCache
{
    /** @var array<string, EntityState> */
    private array $statesById = [];

    // Built lazily: state changes are far more frequent than full-cache reads.
    private ?EntityStateCollection $collection = null;

    /** @param iterable<EntityState> $states */
    public function replaceAll(iterable $states): void
    {
        $this->statesById = [];

        foreach ($states as $state) {
            $this->statesById[$state->entityId->value] = $state;
        }

        $this->collection = null;
    }

    public function applyChange(StateChange $change): void
    {
        if ($change->to === null) {
            unset($this->statesById[$change->entityId->value]);
        } else {
            $this->statesById[$change->entityId->value] = $change->to;
        }

        $this->collection = null;
    }

    public function find(EntityId $entityId): ?EntityState
    {
        return $this->statesById[$entityId->value] ?? null;
    }

    public function listEntityIds(): EntityIdCollection
    {
        return $this->getAllStates()->listEntityIds();
    }

    public function getAllStates(): EntityStateCollection
    {
        return $this->collection ??= EntityStateCollection::keyedByEntityId($this->statesById);
    }

    public function filterBySelector(?Selector $selector): EntityStateCollection
    {
        return $selector === null ? $this->getAllStates() : $this->getAllStates()->filterBySelector($selector);
    }

    public function count(): int
    {
        return \count($this->statesById);
    }
}
