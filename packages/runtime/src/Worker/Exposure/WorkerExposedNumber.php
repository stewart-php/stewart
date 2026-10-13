<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exposure\Command\NumberCommand;
use Stewart\Contracts\Exposure\ExposedNumber;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\NumberConfig;

/** @extends WorkerExposedEntity<NumberConfig> */
final class WorkerExposedNumber extends WorkerExposedEntity implements ExposedNumber
{
    public function getConfig(): NumberConfig
    {
        return $this->findConfig();
    }

    public function updateConfig(NumberConfig $config): void
    {
        $this->sendReconfiguration($config);
    }

    public function watchCommands(): EventStream
    {
        /** @var EventStream<NumberCommand> */
        return $this->watchOwnCommands();
    }

    public function getValue(): int|float|null
    {
        $value = $this->findStateValue();

        return \is_int($value) || \is_float($value) ? $value : null;
    }

    public function setValue(int|float|null $value, ?array $attributes = null): void
    {
        $this->sendChange(new ExposedStateChange(new ExposedState($this->getConfig()->formatState($value)), $attributes));
    }
}
