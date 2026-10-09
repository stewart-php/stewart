<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use DateTimeInterface;
use Stewart\Contracts\Exception\ExposureException;

/** @phpstan-import-type ExposedAttributes from ExposedEntity */
interface ExposedSensor extends ExposedEntity
{
    public function getConfig(): SensorConfig;

    /** @throws ExposureException */
    public function updateConfig(SensorConfig $config): void;

    public function getValue(): int|float|string|null;

    /**
     * @param ExposedAttributes|null $attributes
     * @throws ExposureException
     */
    public function setValue(int|float|string|DateTimeInterface|null $value, ?array $attributes = null): void;
}
