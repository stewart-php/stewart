<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\ExposedEntity;
use Stewart\Contracts\Exposure\ExposedEntityConfig;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Runtime\Model\ResourceScope;

/**
 * @phpstan-import-type ExposedAttributes from ExposedEntity
 *
 * @template TConfig of ExposedEntityConfig
 */
abstract class WorkerExposedEntity implements ExposedEntity, ExposedHandle
{
    private ?EntityId $entityId = null;

    private ?ExposedState $state = null;

    /** @var ExposedAttributes */
    private array $attributes = [];

    private bool $available = true;

    private bool $released = false;

    /** @param TConfig $config */
    public function __construct(
        private readonly ExposureRequester $requester,
        private readonly ExposedHandleRegistry $handles,
        protected readonly ResourceScope $scope,
        protected readonly ExposedEntityKey $key,
        private ExposedEntityConfig $config,
    ) {}

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

    public function remove(): void
    {
        $this->assertNotReleased();
        $this->requester->requestRemoval($this->scope, $this->key);
        $this->handles->forgetHandle($this->scope, $this->key);
        $this->released = true;
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

    public function markReleased(): void
    {
        $this->released = true;
    }

    /** @return TConfig */
    protected function findConfig(): ExposedEntityConfig
    {
        return $this->config;
    }

    /**
     * @param TConfig $config
     * @throws ExposureException
     */
    protected function sendReconfiguration(ExposedEntityConfig $config): void
    {
        $this->assertNotReleased();
        $snapshot = $this->requester->requestReconfiguration($this->scope, $this->key, $config);
        $this->config = $config;

        if ($snapshot !== null) {
            $this->applySnapshot($snapshot);
        }
    }

    /** @throws ExposureException */
    protected function sendChange(ExposedStateChange $change): void
    {
        $this->assertNotReleased();
        $this->requester->requestUpdate($this->scope, $this->key, $change);

        $this->state = $change->state ?? $this->state;
        $this->attributes = $change->attributes ?? $this->attributes;
        $this->available = $change->available ?? $this->available;
    }

    protected function findStateValue(): int|float|string|bool|null
    {
        return $this->state?->value;
    }

    /** @throws ExposureException */
    private function assertNotReleased(): void
    {
        if ($this->released) {
            throw ExposureException::removed($this->key);
        }
    }
}
