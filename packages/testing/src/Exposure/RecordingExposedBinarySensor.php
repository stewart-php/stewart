<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\Exposure\BinarySensorConfig;
use Stewart\Contracts\Exposure\ExposedBinarySensor;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;

final class RecordingExposedBinarySensor extends RecordingExposedEntity implements ExposedBinarySensor
{
    public function __construct(
        ExposedEntityKey $key,
        public readonly BinarySensorConfig $config,
        ?ExposedEntitySnapshot $seeded,
    ) {
        parent::__construct($key, $seeded);
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
