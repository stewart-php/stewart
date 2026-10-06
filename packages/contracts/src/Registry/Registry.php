<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\Registry\Collection\AreaCollection;
use Stewart\Contracts\Registry\Collection\DeviceCollection;
use Stewart\Contracts\Registry\Collection\FloorCollection;
use Stewart\Contracts\Registry\Collection\LabelCollection;
use Stewart\Contracts\Registry\Collection\RegisteredEntityCollection;

interface Registry
{
    public function listAreas(): AreaCollection;

    /** @throws IdentifierException */
    public function findArea(AreaId|string $areaId): ?Area;

    public function findAreaByName(string $name): ?Area;

    /** @throws IdentifierException */
    public function listAreasOnFloor(FloorId|string $floorId): AreaCollection;

    public function listFloors(): FloorCollection;

    /** @throws IdentifierException */
    public function findFloor(FloorId|string $floorId): ?Floor;

    public function findFloorByName(string $name): ?Floor;

    public function listLabels(): LabelCollection;

    /** @throws IdentifierException */
    public function findLabel(LabelId|string $labelId): ?Label;

    public function findLabelByName(string $name): ?Label;

    public function listDevices(): DeviceCollection;

    /** @throws IdentifierException */
    public function findDevice(DeviceId|string $deviceId): ?Device;

    /** @throws IdentifierException */
    public function listDevicesInArea(AreaId|string $areaId): DeviceCollection;

    public function listEntities(): RegisteredEntityCollection;

    /** @throws IdentifierException */
    public function findEntity(EntityId|string $entityId): ?RegisteredEntity;

    /** @throws IdentifierException */
    public function findEntityPlacement(EntityId|string $entityId): EntityPlacement;
}
