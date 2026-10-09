<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\Collection\ExposedStateChangeCollection;
use Stewart\Contracts\Exposure\ExposedEntity;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;

/** @phpstan-import-type ExposedAttributes from ExposedEntity */
abstract class RecordingExposedEntity implements ExposedEntity
{
    public private(set) ExposedStateChangeCollection $changes;

    public private(set) bool $removed = false;

    private ?EntityId $entityId = null;

    private ?ExposedState $state = null;

    /** @var ExposedAttributes */
    private array $attributes = [];

    private bool $available = true;

    public function __construct(private readonly ExposedEntityKey $key, ?ExposedEntitySnapshot $seeded)
    {
        $this->changes = ExposedStateChangeCollection::empty();

        if ($seeded !== null) {
            $this->entityId = $seeded->entityId;
            $this->state = $seeded->state;
            $this->attributes = $seeded->attributes;
            $this->available = $seeded->available;
        }
    }

    public function getKey(): ExposedEntityKey
    {
        return $this->key;
    }

    public function getEntityId(): ?EntityId
    {
        return $this->entityId;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function setAttributes(array $attributes): void
    {
        $this->recordChange(new ExposedStateChange(attributes: $attributes));
    }

    public function markAvailable(): void
    {
        $this->recordChange(new ExposedStateChange(available: true));
    }

    public function markUnavailable(): void
    {
        $this->recordChange(new ExposedStateChange(available: false));
    }

    public function remove(): void
    {
        $this->assertNotRemoved();
        $this->removed = true;
    }

    /** @throws ExposureException */
    protected function recordChange(ExposedStateChange $change): void
    {
        $this->assertNotRemoved();
        $this->changes = $this->changes->withChange($change);
        $this->state = $change->state ?? $this->state;
        $this->attributes = $change->attributes ?? $this->attributes;
        $this->available = $change->available ?? $this->available;
    }

    protected function findStateValue(): int|float|string|bool|null
    {
        return $this->state?->value;
    }

    /** @throws ExposureException */
    private function assertNotRemoved(): void
    {
        if ($this->removed) {
            throw ExposureException::removed($this->key);
        }
    }
}
