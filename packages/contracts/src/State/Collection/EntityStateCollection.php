<?php

declare(strict_types=1);

namespace Stewart\Contracts\State\Collection;

use Stewart\Contracts\Collection\KeyedCollection;
use Stewart\Contracts\Entity\Collection\EntityIdCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\EntityState;

/** @extends KeyedCollection<string, EntityState> */
final readonly class EntityStateCollection extends KeyedCollection
{
    /** @param iterable<EntityState> $states */
    public static function keyedByEntityId(iterable $states): self
    {
        return self::keyedBy($states, static fn(EntityState $state): string => $state->entityId->value);
    }

    public function find(EntityId $entityId): ?EntityState
    {
        return $this->elementAt($entityId->value);
    }

    public function withState(EntityState $state): self
    {
        return $this->withElementAt($state->entityId->value, $state);
    }

    public function withoutState(EntityId $entityId): self
    {
        return $this->withoutKey($entityId->value);
    }

    public function listEntityIds(): EntityIdCollection
    {
        return EntityIdCollection::fromIds($this->mapToList(static fn(EntityState $state): EntityId => $state->entityId));
    }

    public function filterBySelector(Selector $selector): self
    {
        $exactPattern = $selector->findExactPattern();

        if ($exactPattern !== null) {
            $state = $this->elementAt($exactPattern);

            return $state === null ? self::empty() : new self([$exactPattern => $state]);
        }

        return $this->filter(static fn(EntityState $state): bool => $selector->matches($state->entityId->value));
    }

    public function sortedByEntityId(): self
    {
        return $this->sortedBy(static fn(EntityState $a, EntityState $b): int => strcmp($a->entityId->value, $b->entityId->value));
    }

    /** @return array<string, self> */
    public function groupByDomain(): array
    {
        $grouped = [];

        foreach ($this as $key => $state) {
            $grouped[$state->getDomain()][$key] = $state;
        }

        return array_map(static fn(array $states): self => new self($states), $grouped);
    }

    /** @return list<string> */
    public function listDomains(): array
    {
        $domains = array_values(array_unique($this->mapToList(static fn(EntityState $state): string => $state->getDomain())));
        sort($domains);

        return $domains;
    }
}
