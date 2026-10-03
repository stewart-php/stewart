<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Contracts\State\Collection\StateChangeCollection;
use Stewart\Contracts\State\StateChange;
use Stewart\Runtime\Ipc\Wire\Collection\EncodedStateChangeCollection;
use Stewart\Runtime\Json\JsonFragment;
use Stewart\Runtime\Json\WireMapper;

final readonly class StateChangesFragment implements JsonFragment
{
    private function __construct(
        public StateChangeCollection $collection,
        private ?EncodedStateChangeCollection $encodedChanges,
    ) {}

    public static function fromCollection(StateChangeCollection $changes): self
    {
        return self::fromEncodedChanges(EncodedStateChangeCollection::fromChanges($changes->mapToList(static fn(StateChange $change): EncodedStateChange => new EncodedStateChange($change))));
    }

    public static function fromEncodedChanges(EncodedStateChangeCollection $encodedChanges): self
    {
        return new self(
            StateChangeCollection::fromChanges($encodedChanges->mapToList(static fn(EncodedStateChange $encoded): StateChange => $encoded->change)),
            $encodedChanges,
        );
    }

    public static function fromDecodedValue(mixed $value, string $path, WireMapper $mapper): static
    {
        return new self(StateChangeCollection::fromChanges($mapper->decodeObjectList(StateChange::class, $value, $path)), null);
    }

    public function encodeToJson(WireMapper $mapper): string
    {
        if ($this->encodedChanges === null) {
            return $mapper->encodeObjectList($this->collection->listValues());
        }

        return '[' . implode(',', $this->encodedChanges->mapToList(static fn(EncodedStateChange $encoded): string => $encoded->encodeToJson($mapper))) . ']';
    }
}
