<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use DateTimeInterface;
use Stewart\Contracts\Exposure\ExposedSensor;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\SensorConfig;

/** @extends RecordingExposedEntity<SensorConfig> */
final class RecordingExposedSensor extends RecordingExposedEntity implements ExposedSensor
{
    public function getConfig(): SensorConfig
    {
        return $this->config;
    }

    public function updateConfig(SensorConfig $config): void
    {
        $this->recordConfig($config);
    }

    public function getValue(): int|float|string|null
    {
        $value = $this->findStateValue();

        return \is_bool($value) ? null : $value;
    }

    public function setValue(int|float|string|DateTimeInterface|null $value, ?array $attributes = null): void
    {
        $this->recordChange(new ExposedStateChange(new ExposedState($this->config->formatState($value)), $attributes));
    }
}
