<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

use Stewart\Contracts\Registry\Collection\LabelIdCollection;
use Stewart\Contracts\Wire\ListOf;

final readonly class Device
{
    /** @param list<LabelId> $labelIds */
    public function __construct(
        public DeviceId $deviceId,
        public ?string $name = null,
        public ?string $nameByUser = null,
        public ?AreaId $areaId = null,
        #[ListOf(LabelId::class)]
        public array $labelIds = [],
        public ?string $manufacturer = null,
        public ?string $model = null,
        public ?string $disabledBy = null,
    ) {}

    public function getDisplayName(): ?string
    {
        return $this->nameByUser ?? $this->name;
    }

    public function listLabelIds(): LabelIdCollection
    {
        return LabelIdCollection::fromIds($this->labelIds);
    }

    public function isDisabled(): bool
    {
        return $this->disabledBy !== null;
    }
}
