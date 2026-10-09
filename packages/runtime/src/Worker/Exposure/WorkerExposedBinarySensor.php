<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\Exposure\ExposedBinarySensor;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;

final class WorkerExposedBinarySensor extends WorkerExposedEntity implements ExposedBinarySensor
{
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
