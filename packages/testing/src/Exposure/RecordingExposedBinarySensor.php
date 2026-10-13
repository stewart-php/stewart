<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\Exposure\BinarySensorConfig;
use Stewart\Contracts\Exposure\ExposedBinarySensor;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;

/** @extends RecordingExposedEntity<BinarySensorConfig> */
final class RecordingExposedBinarySensor extends RecordingExposedEntity implements ExposedBinarySensor
{
    public function getConfig(): BinarySensorConfig
    {
        return $this->config;
    }

    public function updateConfig(BinarySensorConfig $config): void
    {
        $this->recordConfig($config);
    }

    public function getValue(): ?bool
    {
        $value = $this->findStateValue();

        return \is_bool($value) ? $value : null;
    }

    public function setOn(?array $attributes = null): void
    {
        $this->recordChange(new ExposedStateChange(new ExposedState(true), $attributes));
    }

    public function setOff(?array $attributes = null): void
    {
        $this->recordChange(new ExposedStateChange(new ExposedState(false), $attributes));
    }
}
