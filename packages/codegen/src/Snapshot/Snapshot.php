<?php

declare(strict_types=1);

namespace Stewart\Codegen\Snapshot;

use Stewart\Client\Registry\Collection\EntityRegistryCollection;
use Stewart\Client\Registry\EntityRegistryEntry;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;

final readonly class Snapshot
{
    /** @param array<string, mixed> $services */
    private function __construct(
        public string $haVersion,
        public EntityStateCollection $states,
        public EntityRegistryCollection $registry,
        public array $services,
    ) {}

    /**
     * @param iterable<EntityState> $states
     * @param iterable<EntityRegistryEntry> $registry
     * @param array<string, mixed> $services
     */
    public static function fromParts(string $haVersion, iterable $states, iterable $registry, array $services): self
    {
        ksort($services);

        return new self(
            $haVersion,
            EntityStateCollection::keyedByEntityId($states)->sortedByEntityId(),
            EntityRegistryCollection::keyedByEntityId($registry)->sortedBy(
                static fn(EntityRegistryEntry $a, EntityRegistryEntry $b): int => strcmp($a->entityId->value, $b->entityId->value),
            ),
            $services,
        );
    }
}
