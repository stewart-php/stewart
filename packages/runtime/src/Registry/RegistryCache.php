<?php

declare(strict_types=1);

namespace Stewart\Runtime\Registry;

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

final class RegistryCache implements Registry
{
    private IndexedRegistry $registry;

    private int $revision = 0;

    public function __construct()
    {
        $this->registry = IndexedRegistry::empty();
    }

    public function replaceIfNewer(IndexedRegistry $registry, int $revision): bool
    {
        if ($revision <= $this->revision) {
            return false;
        }

        $this->registry = $registry;
        $this->revision = $revision;

        return true;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getIndexedRegistry(): IndexedRegistry
    {
        return $this->registry;
    }

    public function listAreas(): AreaCollection
    {
        return $this->registry->listAreas();
    }

    public function findArea(AreaId|string $areaId): ?Area
    {
        return $this->registry->findArea($areaId);
    }

    public function findAreaByName(string $name): ?Area
    {
        return $this->registry->findAreaByName($name);
    }

    public function listAreasOnFloor(FloorId|string $floorId): AreaCollection
    {
        return $this->registry->listAreasOnFloor($floorId);
    }

    public function listFloors(): FloorCollection
    {
        return $this->registry->listFloors();
    }

    public function findFloor(FloorId|string $floorId): ?Floor
    {
        return $this->registry->findFloor($floorId);
    }

    public function findFloorByName(string $name): ?Floor
    {
        return $this->registry->findFloorByName($name);
    }

    public function listLabels(): LabelCollection
    {
        return $this->registry->listLabels();
    }

    public function findLabel(LabelId|string $labelId): ?Label
    {
        return $this->registry->findLabel($labelId);
    }

    public function findLabelByName(string $name): ?Label
    {
        return $this->registry->findLabelByName($name);
    }

    public function listDevices(): DeviceCollection
    {
        return $this->registry->listDevices();
    }

    public function findDevice(DeviceId|string $deviceId): ?Device
    {
        return $this->registry->findDevice($deviceId);
    }

    public function listDevicesInArea(AreaId|string $areaId): DeviceCollection
    {
        return $this->registry->listDevicesInArea($areaId);
    }

    public function listEntities(): RegisteredEntityCollection
    {
        return $this->registry->listEntities();
    }

    public function findEntity(EntityId|string $entityId): ?RegisteredEntity
    {
        return $this->registry->findEntity($entityId);
    }

    public function findEntityPlacement(EntityId|string $entityId): EntityPlacement
    {
        return $this->registry->findEntityPlacement($entityId);
    }
}
