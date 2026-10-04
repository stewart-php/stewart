<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Contracts\History\Collection\HistoricalStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Runtime\Json\JsonFragment;
use Stewart\Runtime\Json\WireMapper;

final class HistoricalStatesFragment implements JsonFragment
{
    private ?string $json = null;

    private function __construct(public readonly HistoricalStateCollection $collection) {}

    public static function fromCollection(HistoricalStateCollection $states): self
    {
        return new self($states);
    }

    public static function fromDecodedValue(mixed $value, string $path, WireMapper $mapper): static
    {
        return new self(HistoricalStateCollection::fromStates($mapper->decodeObjectList(EntityState::class, $value, $path)));
    }

    public function encodeToJson(WireMapper $mapper): string
    {
        return $this->json ??= $mapper->encodeObjectList($this->collection->listValues());
    }
}
