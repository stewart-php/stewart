<?php

declare(strict_types=1);

namespace Stewart\Client\Registry;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stewart\Contracts\Registry\Area;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\Device;
use Stewart\Contracts\Registry\DeviceId;
use Stewart\Contracts\Registry\Floor;
use Stewart\Contracts\Registry\FloorId;
use Stewart\Contracts\Registry\Label;
use Stewart\Contracts\Registry\LabelId;

final readonly class RegistryDecoder
{
    public function __construct(private LoggerInterface $logger = new NullLogger()) {}

    /** @param array<array-key, mixed> $raw */
    public function decodeAreaOrSkip(array $raw): ?Area
    {
        $areaId = AreaId::tryFromString(RegistryRow::readString($raw, 'area_id') ?? '');

        if ($areaId === null) {
            return $this->skipRow('area');
        }

        return new Area(
            areaId: $areaId,
            name: RegistryRow::readString($raw, 'name') ?? $areaId->value,
            floorId: FloorId::tryFromString(RegistryRow::readString($raw, 'floor_id') ?? ''),
            aliases: RegistryRow::readStrings($raw, 'aliases'),
            labelIds: RegistryRow::readLabelIds($raw)->listValues(),
            icon: RegistryRow::readString($raw, 'icon'),
        );
    }

    /** @param array<array-key, mixed> $raw */
    public function decodeFloorOrSkip(array $raw): ?Floor
    {
        $floorId = FloorId::tryFromString(RegistryRow::readString($raw, 'floor_id') ?? '');

        if ($floorId === null) {
            return $this->skipRow('floor');
        }

        $level = $raw['level'] ?? null;

        return new Floor(
            floorId: $floorId,
            name: RegistryRow::readString($raw, 'name') ?? $floorId->value,
            level: \is_int($level) ? $level : null,
            aliases: RegistryRow::readStrings($raw, 'aliases'),
            icon: RegistryRow::readString($raw, 'icon'),
        );
    }

    /** @param array<array-key, mixed> $raw */
    public function decodeLabelOrSkip(array $raw): ?Label
    {
        $labelId = LabelId::tryFromString(RegistryRow::readString($raw, 'label_id') ?? '');

        if ($labelId === null) {
            return $this->skipRow('label');
        }

        return new Label(
            labelId: $labelId,
            name: RegistryRow::readString($raw, 'name') ?? $labelId->value,
            color: RegistryRow::readString($raw, 'color'),
            icon: RegistryRow::readString($raw, 'icon'),
            description: RegistryRow::readString($raw, 'description'),
        );
    }

    /** @param array<array-key, mixed> $raw */
    public function decodeDeviceOrSkip(array $raw): ?Device
    {
        $deviceId = DeviceId::tryFromString(RegistryRow::readString($raw, 'id') ?? '');

        if ($deviceId === null) {
            return $this->skipRow('device');
        }

        return new Device(
            deviceId: $deviceId,
            name: RegistryRow::readString($raw, 'name'),
            nameByUser: RegistryRow::readString($raw, 'name_by_user'),
            areaId: AreaId::tryFromString(RegistryRow::readString($raw, 'area_id') ?? ''),
            labelIds: RegistryRow::readLabelIds($raw)->listValues(),
            manufacturer: RegistryRow::readString($raw, 'manufacturer'),
            model: RegistryRow::readString($raw, 'model'),
            disabledBy: RegistryRow::readString($raw, 'disabled_by'),
        );
    }

    private function skipRow(string $registry): null
    {
        $this->logger->debug('Skipped a {registry} registry entry without an id', ['registry' => $registry]);

        return null;
    }
}
