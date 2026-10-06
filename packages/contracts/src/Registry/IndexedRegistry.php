<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\Collection\AreaCollection;
use Stewart\Contracts\Registry\Collection\DeviceCollection;
use Stewart\Contracts\Registry\Collection\FloorCollection;
use Stewart\Contracts\Registry\Collection\LabelCollection;
use Stewart\Contracts\Registry\Collection\LabelIdCollection;
use Stewart\Contracts\Registry\Collection\RegisteredEntityCollection;

final readonly class IndexedRegistry implements Registry
{
    /** @var array<string, EntityPlacement> */
    private array $placements;

    private function __construct(
        private AreaCollection $areas,
        private FloorCollection $floors,
        private LabelCollection $labels,
        private DeviceCollection $devices,
        private RegisteredEntityCollection $entities,
    ) {
        $placements = [];

        foreach ($entities as $entity) {
            $placements[$entity->entityId->value] = $this->placeEntity($entity);
        }

        $this->placements = $placements;
    }

    public static function fromParts(
        AreaCollection $areas,
        FloorCollection $floors,
        LabelCollection $labels,
        DeviceCollection $devices,
        RegisteredEntityCollection $entities,
    ): self {
        return new self($areas, $floors, $labels, $devices, $entities);
    }

    public static function empty(): self
    {
        return new self(AreaCollection::empty(), FloorCollection::empty(), LabelCollection::empty(), DeviceCollection::empty(), RegisteredEntityCollection::empty());
    }

    public function listAreas(): AreaCollection
    {
        return $this->areas;
    }

    public function findArea(AreaId|string $areaId): ?Area
    {
        return $this->areas->find(AreaId::fromStringOrId($areaId));
    }

    public function findAreaByName(string $name): ?Area
    {
        return $this->areas->findFirstWhere(static fn(Area $area): bool => $area->isNamed($name));
    }

    public function listAreasOnFloor(FloorId|string $floorId): AreaCollection
    {
        $floorId = FloorId::fromStringOrId($floorId);

        return $this->areas->filter(static fn(Area $area): bool => $area->floorId?->equals($floorId) ?? false);
    }

    public function listFloors(): FloorCollection
    {
        return $this->floors;
    }

    public function findFloor(FloorId|string $floorId): ?Floor
    {
        return $this->floors->find(FloorId::fromStringOrId($floorId));
    }

    public function findFloorByName(string $name): ?Floor
    {
        return $this->floors->findFirstWhere(static fn(Floor $floor): bool => $floor->isNamed($name));
    }

    public function listLabels(): LabelCollection
    {
        return $this->labels;
    }

    public function findLabel(LabelId|string $labelId): ?Label
    {
        return $this->labels->find(LabelId::fromStringOrId($labelId));
    }

    public function findLabelByName(string $name): ?Label
    {
        return $this->labels->findFirstWhere(static fn(Label $label): bool => $label->isNamed($name));
    }

    public function listDevices(): DeviceCollection
    {
        return $this->devices;
    }

    public function findDevice(DeviceId|string $deviceId): ?Device
    {
        return $this->devices->find(DeviceId::fromStringOrId($deviceId));
    }

    public function listDevicesInArea(AreaId|string $areaId): DeviceCollection
    {
        $areaId = AreaId::fromStringOrId($areaId);

        return $this->devices->filter(static fn(Device $device): bool => $device->areaId?->equals($areaId) ?? false);
    }

    public function listEntities(): RegisteredEntityCollection
    {
        return $this->entities;
    }

    public function findEntity(EntityId|string $entityId): ?RegisteredEntity
    {
        return $this->entities->find(EntityId::fromStringOrId($entityId));
    }

    public function findEntityPlacement(EntityId|string $entityId): EntityPlacement
    {
        $entityId = EntityId::fromStringOrId($entityId);

        return $this->placements[$entityId->value] ?? EntityPlacement::unregistered($entityId);
    }

    // Mirrors Home Assistant target resolution: an entity's own area overrides its device's.
    private function placeEntity(RegisteredEntity $entity): EntityPlacement
    {
        $device = $entity->deviceId === null ? null : $this->devices->find($entity->deviceId);
        $areaId = $entity->areaId ?? $device?->areaId;
        $area = $areaId === null ? null : $this->areas->find($areaId);

        return new EntityPlacement(
            entityId: $entity->entityId,
            deviceId: $entity->deviceId,
            areaId: $areaId,
            floorId: $area?->floorId,
            labelIds: $this->mergeLabelIds($entity->labelIds, $device->labelIds ?? [], $area->labelIds ?? []),
            indirectlyTargetable: !$entity->isHidden() && !$entity->hasEntityCategory(),
        );
    }

    /** @param list<LabelId> ...$labelIdLists */
    private function mergeLabelIds(array ...$labelIdLists): LabelIdCollection
    {
        $unique = [];

        foreach ($labelIdLists as $labelIds) {
            foreach ($labelIds as $labelId) {
                $unique[$labelId->value] = $labelId;
            }
        }

        return LabelIdCollection::fromIds($unique);
    }
}
