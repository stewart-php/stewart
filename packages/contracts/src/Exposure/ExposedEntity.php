<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\ExposureException;

/** @phpstan-type ExposedAttributes array<string, mixed> */
interface ExposedEntity
{
    public function getKey(): ExposedEntityKey;

    // Null until Home Assistant has created the entity, which waits for the stewart integration session.
    public function getEntityId(): ?EntityId;

    /** @return ExposedAttributes */
    public function getAttributes(): array;

    public function isAvailable(): bool;

    /**
     * @param ExposedAttributes $attributes
     * @throws ExposureException
     */
    public function setAttributes(array $attributes): void;

    /** @throws ExposureException */
    public function markAvailable(): void;

    /** @throws ExposureException */
    public function markUnavailable(): void;

    /** @throws ExposureException */
    public function remove(): void;
}
