<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Runtime\Json\JsonFragment;
use Stewart\Runtime\Json\WireMapper;

final class EntityStatesFragment implements JsonFragment
{
    private ?string $json = null;

    private function __construct(public readonly EntityStateCollection $collection) {}

    public static function fromCollection(EntityStateCollection $states): self
    {
        return new self($states);
    }

    public static function fromDecodedValue(mixed $value, string $path, WireMapper $mapper): static
    {
        return new self(EntityStateCollection::keyedByEntityId($mapper->decodeObjectList(EntityState::class, $value, $path)));
    }

    public function encodeToJson(WireMapper $mapper): string
    {
        return $this->json ??= $mapper->encodeObjectList($this->collection->listValues());
    }
}
