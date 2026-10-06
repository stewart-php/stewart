<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\Collection\LabelIdCollection;
use Stewart\Contracts\Wire\ListOf;

final readonly class RegisteredEntity
{
    /** @param list<LabelId> $labelIds */
    public function __construct(
        public EntityId $entityId,
        public ?DeviceId $deviceId = null,
        public ?AreaId $areaId = null,
        #[ListOf(LabelId::class)]
        public array $labelIds = [],
        public ?string $name = null,
        public ?string $entityCategory = null,
        public ?string $hiddenBy = null,
        public ?string $disabledBy = null,
    ) {}

    public function listLabelIds(): LabelIdCollection
    {
        return LabelIdCollection::fromIds($this->labelIds);
    }

    public function isHidden(): bool
    {
        return $this->hiddenBy !== null;
    }

    public function isDisabled(): bool
    {
        return $this->disabledBy !== null;
    }

    public function hasEntityCategory(): bool
    {
        return $this->entityCategory !== null;
    }
}
