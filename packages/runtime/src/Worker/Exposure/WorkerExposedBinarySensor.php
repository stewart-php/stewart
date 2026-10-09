<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\Exposure\BinarySensorConfig;
use Stewart\Contracts\Exposure\ExposedBinarySensor;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;

/** @extends WorkerExposedEntity<BinarySensorConfig> */
final class WorkerExposedBinarySensor extends WorkerExposedEntity implements ExposedBinarySensor
{
    public function getConfig(): BinarySensorConfig
    {
        return $this->findConfig();
    }

    public function updateConfig(BinarySensorConfig $config): void
    {
        $this->sendReconfiguration($config);
    }

    public function getValue(): ?bool
    {
        $value = $this->findStateValue();

        return \is_bool($value) ? $value : null;
    }

    public function setOn(?array $attributes = null): void
    {
        $this->sendChange(new ExposedStateChange(new ExposedState(true), $attributes));
    }

    public function setOff(?array $attributes = null): void
    {
        $this->sendChange(new ExposedStateChange(new ExposedState(false), $attributes));
    }
}
