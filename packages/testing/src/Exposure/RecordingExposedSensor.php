<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use DateTimeInterface;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedSensor;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\SensorConfig;

final class RecordingExposedSensor extends RecordingExposedEntity implements ExposedSensor
{
    public function __construct(
        ExposedEntityKey $key,
        public private(set) SensorConfig $config,
        ?ExposedEntitySnapshot $seeded,
    ) {
        parent::__construct($key, $seeded);
    }

    public function getConfig(): SensorConfig
    {
        return $this->config;
    }

    public function updateConfig(SensorConfig $config): void
    {
        $this->assertNotRemoved();
        $this->config = $config;
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
