<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\Collection\LabelIdCollection;

final readonly class EntityPlacement
{
    public function __construct(
        public EntityId $entityId,
        public ?DeviceId $deviceId,
        public ?AreaId $areaId,
        public ?FloorId $floorId,
        public LabelIdCollection $labelIds,
        public bool $indirectlyTargetable,
    ) {}

    public static function unregistered(EntityId $entityId): self
    {
        return new self($entityId, null, null, null, LabelIdCollection::empty(), true);
    }
}
