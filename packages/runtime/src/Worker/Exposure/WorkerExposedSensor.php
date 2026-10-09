<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use DateTimeInterface;
use Stewart\Contracts\Exposure\ExposedSensor;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\SensorConfig;

/** @extends WorkerExposedEntity<SensorConfig> */
final class WorkerExposedSensor extends WorkerExposedEntity implements ExposedSensor
{
    public function getConfig(): SensorConfig
    {
        return $this->findConfig();
    }

    public function updateConfig(SensorConfig $config): void
    {
        $this->sendReconfiguration($config);
    }

    public function getValue(): int|float|string|null
    {
        $value = $this->findStateValue();

        return \is_bool($value) ? null : $value;
    }

    public function setValue(int|float|string|DateTimeInterface|null $value, ?array $attributes = null): void
    {
        $this->sendChange(new ExposedStateChange(new ExposedState($this->getConfig()->formatState($value)), $attributes));
    }
}
