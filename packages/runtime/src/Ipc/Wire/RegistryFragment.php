<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Registry\Area;
use Stewart\Contracts\Registry\Collection\AreaCollection;
use Stewart\Contracts\Registry\Collection\DeviceCollection;
use Stewart\Contracts\Registry\Collection\FloorCollection;
use Stewart\Contracts\Registry\Collection\LabelCollection;
use Stewart\Contracts\Registry\Collection\RegisteredEntityCollection;
use Stewart\Contracts\Registry\Device;
use Stewart\Contracts\Registry\Floor;
use Stewart\Contracts\Registry\IndexedRegistry;
use Stewart\Contracts\Registry\Label;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Runtime\Json\JsonFragment;
use Stewart\Runtime\Json\WireMapper;

final class RegistryFragment implements JsonFragment
{
    private ?string $json = null;

    private function __construct(public readonly IndexedRegistry $registry) {}

    public static function fromRegistry(IndexedRegistry $registry): self
    {
        return new self($registry);
    }

    public static function fromDecodedValue(mixed $value, string $path, WireMapper $mapper): static
    {
        if (!\is_array($value)) {
            throw JsonShapeException::wrongType($path, 'an object', get_debug_type($value));
        }

        return new self(IndexedRegistry::fromParts(
            AreaCollection::keyedByAreaId($mapper->decodeObjectList(Area::class, $value['areas'] ?? null, $path . '.areas')),
            FloorCollection::keyedByFloorId($mapper->decodeObjectList(Floor::class, $value['floors'] ?? null, $path . '.floors')),
            LabelCollection::keyedByLabelId($mapper->decodeObjectList(Label::class, $value['labels'] ?? null, $path . '.labels')),
            DeviceCollection::keyedByDeviceId($mapper->decodeObjectList(Device::class, $value['devices'] ?? null, $path . '.devices')),
            RegisteredEntityCollection::keyedByEntityId($mapper->decodeObjectList(RegisteredEntity::class, $value['entities'] ?? null, $path . '.entities')),
        ));
    }

    public function encodeToJson(WireMapper $mapper): string
    {
        return $this->json ??= '{"areas":' . $mapper->encodeObjectList($this->registry->listAreas()->listValues())
            . ',"floors":' . $mapper->encodeObjectList($this->registry->listFloors()->listValues())
            . ',"labels":' . $mapper->encodeObjectList($this->registry->listLabels()->listValues())
            . ',"devices":' . $mapper->encodeObjectList($this->registry->listDevices()->listValues())
            . ',"entities":' . $mapper->encodeObjectList($this->registry->listEntities()->listValues()) . '}';
    }
}
