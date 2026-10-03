<?php

declare(strict_types=1);

namespace Stewart\Contracts\Service;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\Wire\ListOf;

final readonly class ServiceTarget implements ServiceTargetSource
{
    /**
     * @param list<EntityId> $entityIds
     * @param list<string> $deviceIds
     * @param list<string> $areaIds
     * @param list<string> $floorIds
     * @param list<string> $labelIds
     */
    public function __construct(
        #[ListOf(EntityId::class)]
        public array $entityIds = [],
        #[ListOf('string')]
        public array $deviceIds = [],
        #[ListOf('string')]
        public array $areaIds = [],
        #[ListOf('string')]
        public array $floorIds = [],
        #[ListOf('string')]
        public array $labelIds = [],
    ) {}

    /** @throws IdentifierException */
    public static function forEntities(EntityId|string ...$entityIds): self
    {
        return new self(entityIds: array_values(array_map(EntityId::fromStringOrId(...), $entityIds)));
    }

    public static function forDevices(string ...$deviceIds): self
    {
        return new self(deviceIds: array_values($deviceIds));
    }

    public static function forAreas(string ...$areaIds): self
    {
        return new self(areaIds: array_values($areaIds));
    }

    public static function forFloors(string ...$floorIds): self
    {
        return new self(floorIds: array_values($floorIds));
    }

    public static function forLabels(string ...$labelIds): self
    {
        return new self(labelIds: array_values($labelIds));
    }

    public function toServiceTarget(): self
    {
        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->entityIds === []
            && $this->deviceIds === []
            && $this->areaIds === []
            && $this->floorIds === []
            && $this->labelIds === [];
    }

    /** @return array<string, list<string>> */
    public function toArray(): array
    {
        $out = [];

        foreach ([
            'entity_id' => array_map(static fn(EntityId $entityId): string => $entityId->value, $this->entityIds),
            'device_id' => $this->deviceIds,
            'area_id' => $this->areaIds,
            'floor_id' => $this->floorIds,
            'label_id' => $this->labelIds,
        ] as $key => $values) {
            if ($values !== []) {
                $out[$key] = $values;
            }
        }

        return $out;
    }
}
