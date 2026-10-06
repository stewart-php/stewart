<?php

declare(strict_types=1);

namespace Stewart\Codegen\Snapshot;

use Stewart\Client\Registry\Collection\EntityRegistryCollection;
use Stewart\Client\Registry\EntityRegistryEntry;
use Stewart\Contracts\Registry\Area;
use Stewart\Contracts\Registry\Collection\AreaCollection;
use Stewart\Contracts\Registry\Collection\FloorCollection;
use Stewart\Contracts\Registry\Collection\LabelCollection;
use Stewart\Contracts\Registry\Floor;
use Stewart\Contracts\Registry\Label;
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
        public AreaCollection $areas,
        public FloorCollection $floors,
        public LabelCollection $labels,
    ) {}

    /**
     * @param iterable<EntityState> $states
     * @param iterable<EntityRegistryEntry> $registry
     * @param array<string, mixed> $services
     * @param iterable<Area> $areas
     * @param iterable<Floor> $floors
     * @param iterable<Label> $labels
     */
    public static function fromParts(
        string $haVersion,
        iterable $states,
        iterable $registry,
        array $services,
        iterable $areas = [],
        iterable $floors = [],
        iterable $labels = [],
    ): self {
        ksort($services);

        return new self(
            $haVersion,
            EntityStateCollection::keyedByEntityId($states)->sortedByEntityId(),
            EntityRegistryCollection::keyedByEntityId($registry)->sortedBy(
                static fn(EntityRegistryEntry $a, EntityRegistryEntry $b): int => strcmp($a->entityId->value, $b->entityId->value),
            ),
            $services,
            AreaCollection::keyedByAreaId($areas)->sortedBy(static fn(Area $a, Area $b): int => strcmp($a->areaId->value, $b->areaId->value)),
            FloorCollection::keyedByFloorId($floors)->sortedBy(static fn(Floor $a, Floor $b): int => strcmp($a->floorId->value, $b->floorId->value)),
            LabelCollection::keyedByLabelId($labels)->sortedBy(static fn(Label $a, Label $b): int => strcmp($a->labelId->value, $b->labelId->value)),
        );
    }
}
