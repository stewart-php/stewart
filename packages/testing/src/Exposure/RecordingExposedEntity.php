<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\Collection\ExposedStateChangeCollection;
use Stewart\Contracts\Exposure\Command\ExposedCommand;
use Stewart\Contracts\Exposure\ExposedEntity;
use Stewart\Contracts\Exposure\ExposedEntityConfig;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Testing\Time\ManualTimers;

/**
 * @phpstan-import-type ExposedAttributes from ExposedEntity
 *
 * @template TConfig of ExposedEntityConfig
 * @extends RecordingExposure<TConfig>
 */
abstract class RecordingExposedEntity extends RecordingExposure implements ExposedEntity
{
    public private(set) ExposedStateChangeCollection $changes;

    private ?EntityId $entityId = null;

    private ?ExposedState $state = null;

    /** @var ExposedAttributes */
    private array $attributes = [];

    private bool $available = true;

    /** @param TConfig $config */
    public function __construct(ExposedEntityKey $key, ExposedEntityConfig $config, ManualTimers $timers)
    {
        parent::__construct($key, $config, $timers);
        $this->changes = ExposedStateChangeCollection::empty();
    }

    public function applySnapshot(ExposedEntitySnapshot $snapshot): void
    {
        $this->entityId = $snapshot->entityId;
        $this->state = $snapshot->state;
        $this->attributes = $snapshot->attributes;
        $this->available = $snapshot->available;
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

    /** @throws ExposureException */
    protected function recordChange(ExposedStateChange $change): void
    {
        $this->assertNotRemoved();
        $this->changes = $this->changes->withChange($change);
        $this->state = $change->state ?? $this->state;
        $this->attributes = $change->attributes ?? $this->attributes;
        $this->available = $change->available ?? $this->available;
    }

    protected function deliverCommand(ExposedCommand $command): void
    {
        parent::deliverCommand($command);
        $this->state = $command->getRequestedState() ?? $this->state;
    }

    protected function findStateValue(): int|float|string|bool|null
    {
        return $this->state?->value;
    }
}
