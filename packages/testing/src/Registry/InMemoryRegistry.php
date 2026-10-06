<?php

declare(strict_types=1);

namespace Stewart\Testing\Registry;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\Area;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\Collection\AreaCollection;
use Stewart\Contracts\Registry\Collection\DeviceCollection;
use Stewart\Contracts\Registry\Collection\FloorCollection;
use Stewart\Contracts\Registry\Collection\LabelCollection;
use Stewart\Contracts\Registry\Collection\RegisteredEntityCollection;
use Stewart\Contracts\Registry\Device;
use Stewart\Contracts\Registry\DeviceId;
use Stewart\Contracts\Registry\EntityPlacement;
use Stewart\Contracts\Registry\Floor;
use Stewart\Contracts\Registry\FloorId;
use Stewart\Contracts\Registry\IndexedRegistry;
use Stewart\Contracts\Registry\Label;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Contracts\Registry\Registry;

final class InMemoryRegistry implements Registry
{
    /** @var array<string, Area> */
    private array $areas = [];

    /** @var array<string, Floor> */
    private array $floors = [];

    /** @var array<string, Label> */
    private array $labels = [];

    /** @var array<string, Device> */
    private array $devices = [];

    /** @var array<string, RegisteredEntity> */
    private array $entities = [];

    private ?IndexedRegistry $indexed = null;

    public function seedArea(Area $area): self
    {
        $this->areas[$area->areaId->value] = $area;

        return $this->invalidateIndex();
    }

    public function seedFloor(Floor $floor): self
    {
        $this->floors[$floor->floorId->value] = $floor;

        return $this->invalidateIndex();
    }

    public function seedLabel(Label $label): self
    {
        $this->labels[$label->labelId->value] = $label;

        return $this->invalidateIndex();
    }

    public function seedDevice(Device $device): self
    {
        $this->devices[$device->deviceId->value] = $device;

        return $this->invalidateIndex();
    }

    public function seedEntity(RegisteredEntity $entity): self
    {
        $this->entities[$entity->entityId->value] = $entity;

        return $this->invalidateIndex();
    }

    public function listAreas(): AreaCollection
    {
        return $this->getIndexedRegistry()->listAreas();
    }

    public function findArea(AreaId|string $areaId): ?Area
    {
        return $this->getIndexedRegistry()->findArea($areaId);
    }

    public function findAreaByName(string $name): ?Area
    {
        return $this->getIndexedRegistry()->findAreaByName($name);
    }

    public function listAreasOnFloor(FloorId|string $floorId): AreaCollection
    {
        return $this->getIndexedRegistry()->listAreasOnFloor($floorId);
    }

    public function listFloors(): FloorCollection
    {
        return $this->getIndexedRegistry()->listFloors();
    }

    public function findFloor(FloorId|string $floorId): ?Floor
    {
        return $this->getIndexedRegistry()->findFloor($floorId);
    }

    public function findFloorByName(string $name): ?Floor
    {
        return $this->getIndexedRegistry()->findFloorByName($name);
    }

    public function listLabels(): LabelCollection
    {
        return $this->getIndexedRegistry()->listLabels();
    }

    public function findLabel(LabelId|string $labelId): ?Label
    {
        return $this->getIndexedRegistry()->findLabel($labelId);
    }

    public function findLabelByName(string $name): ?Label
    {
        return $this->getIndexedRegistry()->findLabelByName($name);
    }

    public function listDevices(): DeviceCollection
    {
        return $this->getIndexedRegistry()->listDevices();
    }

    public function findDevice(DeviceId|string $deviceId): ?Device
    {
        return $this->getIndexedRegistry()->findDevice($deviceId);
    }

    public function listDevicesInArea(AreaId|string $areaId): DeviceCollection
    {
        return $this->getIndexedRegistry()->listDevicesInArea($areaId);
    }

    public function listEntities(): RegisteredEntityCollection
    {
        return $this->getIndexedRegistry()->listEntities();
    }

    public function findEntity(EntityId|string $entityId): ?RegisteredEntity
    {
        return $this->getIndexedRegistry()->findEntity($entityId);
    }

    public function findEntityPlacement(EntityId|string $entityId): EntityPlacement
    {
        return $this->getIndexedRegistry()->findEntityPlacement($entityId);
    }

    public function getIndexedRegistry(): IndexedRegistry
    {
        return $this->indexed ??= IndexedRegistry::fromParts(
            AreaCollection::keyedByAreaId($this->areas),
            FloorCollection::keyedByFloorId($this->floors),
            LabelCollection::keyedByLabelId($this->labels),
            DeviceCollection::keyedByDeviceId($this->devices),
            RegisteredEntityCollection::keyedByEntityId($this->entities),
        );
    }

    private function invalidateIndex(): self
    {
        $this->indexed = null;

        return $this;
    }
}
