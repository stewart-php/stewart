<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\ExposedEntity;
use Stewart\Contracts\Exposure\ExposedEntityConfig;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;

/**
 * @phpstan-import-type ExposedAttributes from ExposedEntity
 *
 * @template TConfig of ExposedEntityConfig
 * @extends WorkerExposure<TConfig>
 */
abstract class WorkerExposedEntity extends WorkerExposure implements ExposedEntity
{
    private ?EntityId $entityId = null;

    private ?ExposedState $state = null;

    /** @var ExposedAttributes */
    private array $attributes = [];

    private bool $available = true;

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
        $this->sendChange(new ExposedStateChange(attributes: $attributes));
    }

    public function markAvailable(): void
    {
        $this->sendChange(new ExposedStateChange(available: true));
    }

    public function markUnavailable(): void
    {
        $this->sendChange(new ExposedStateChange(available: false));
    }

    public function applySnapshot(ExposedEntitySnapshot $snapshot): void
    {
        $this->entityId = $snapshot->entityId;
        $this->state = $snapshot->state;
        $this->attributes = $snapshot->attributes;
        $this->available = $snapshot->available;
    }

    public function recordCommandedState(ExposedState $state): void
    {
        $this->state = $state;
    }

    /** @throws ExposureException */
    protected function sendChange(ExposedStateChange $change): void
    {
        $this->sendUpdate($change);

        $this->state = $change->state ?? $this->state;
        $this->attributes = $change->attributes ?? $this->attributes;
        $this->available = $change->available ?? $this->available;
    }

    protected function findStateValue(): int|float|string|bool|null
    {
        return $this->state?->value;
    }
}
