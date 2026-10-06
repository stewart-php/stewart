<?php

declare(strict_types=1);

namespace Stewart\Client\Registry;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\DeviceId;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\RegisteredEntity;

final readonly class EntityRegistryEntry
{
    /** @param list<string> $labelIds */
    public function __construct(
        public EntityId $entityId,
        public ?string $disabledBy = null,
        public ?string $hiddenBy = null,
        public ?string $name = null,
        public ?string $areaId = null,
        public ?string $deviceId = null,
        public array $labelIds = [],
        public ?string $entityCategory = null,
    ) {}

    public function isDisabled(): bool
    {
        return $this->disabledBy !== null;
    }

    public function isHidden(): bool
    {
        return $this->hiddenBy !== null;
    }

    /**
     * @param array<array-key, mixed> $raw
     * @throws IdentifierException
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            entityId: new EntityId(RegistryRow::readString($raw, 'entity_id') ?? ''),
            disabledBy: RegistryRow::readString($raw, 'disabled_by'),
            hiddenBy: RegistryRow::readString($raw, 'hidden_by'),
            name: RegistryRow::readString($raw, 'name'),
            areaId: RegistryRow::readString($raw, 'area_id'),
            deviceId: RegistryRow::readString($raw, 'device_id'),
            labelIds: RegistryRow::readStrings($raw, 'labels'),
            entityCategory: RegistryRow::readString($raw, 'entity_category'),
        );
    }

    /** @return array<string, string|list<string>|null> */
    public function toArray(): array
    {
        return [
            'entity_id' => $this->entityId->value,
            'disabled_by' => $this->disabledBy,
            'hidden_by' => $this->hiddenBy,
            'name' => $this->name,
            'area_id' => $this->areaId,
            'device_id' => $this->deviceId,
            'labels' => $this->labelIds,
            'entity_category' => $this->entityCategory,
        ];
    }

    public function toRegisteredEntity(): RegisteredEntity
    {
        return new RegisteredEntity(
            entityId: $this->entityId,
            deviceId: DeviceId::tryFromString($this->deviceId ?? ''),
            areaId: AreaId::tryFromString($this->areaId ?? ''),
            labelIds: array_values(array_filter(array_map(LabelId::tryFromString(...), $this->labelIds))),
            name: $this->name,
            entityCategory: $this->entityCategory,
            hiddenBy: $this->hiddenBy,
            disabledBy: $this->disabledBy,
        );
    }
}
