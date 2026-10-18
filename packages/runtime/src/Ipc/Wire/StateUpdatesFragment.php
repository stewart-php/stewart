<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Runtime\Ipc\Wire\Collection\EncodedStateUpdateCollection;
use Stewart\Runtime\Json\JsonFragment;
use Stewart\Runtime\Json\WireMapper;
use Stewart\Support\State\Collection\StateUpdateCollection;
use Stewart\Support\State\StateUpdate;

final readonly class StateUpdatesFragment implements JsonFragment
{
    private function __construct(
        public StateUpdateCollection $collection,
        private ?EncodedStateUpdateCollection $encodedUpdates,
    ) {}

    public static function fromCollection(StateUpdateCollection $updates): self
    {
        return self::fromEncodedUpdates(EncodedStateUpdateCollection::fromList($updates->mapToList(static fn(StateUpdate $update): EncodedStateUpdate => new EncodedStateUpdate($update))));
    }

    public static function fromEncodedUpdates(EncodedStateUpdateCollection $encodedUpdates): self
    {
        return new self(
            StateUpdateCollection::fromList($encodedUpdates->mapToList(static fn(EncodedStateUpdate $encoded): StateUpdate => $encoded->update)),
            $encodedUpdates,
        );
    }

    public static function fromDecodedValue(mixed $value, string $path, WireMapper $mapper): static
    {
        return new self(StateUpdateCollection::fromList($mapper->decodeObjectList(StateUpdate::class, $value, $path)), null);
    }

    public function encodeToJson(WireMapper $mapper): string
    {
        if ($this->encodedUpdates === null) {
            return $mapper->encodeObjectList($this->collection->listValues());
        }

        return '[' . implode(',', $this->encodedUpdates->mapToList(static fn(EncodedStateUpdate $encoded): string => $encoded->encodeToJson($mapper))) . ']';
    }
}
