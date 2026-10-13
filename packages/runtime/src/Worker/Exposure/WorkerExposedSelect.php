<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exposure\Command\SelectCommand;
use Stewart\Contracts\Exposure\ExposedSelect;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\SelectConfig;

/** @extends WorkerExposedEntity<SelectConfig> */
final class WorkerExposedSelect extends WorkerExposedEntity implements ExposedSelect
{
    public function getConfig(): SelectConfig
    {
        return $this->findConfig();
    }

    public function updateConfig(SelectConfig $config): void
    {
        $this->sendReconfiguration($config);
    }

    public function watchCommands(): EventStream
    {
        /** @var EventStream<SelectCommand> */
        return $this->watchOwnCommands();
    }

    public function getOption(): ?string
    {
        $value = $this->findStateValue();

        return \is_string($value) ? $value : null;
    }

    public function setOption(?string $option, ?array $attributes = null): void
    {
        $this->sendChange(new ExposedStateChange(new ExposedState($option), $attributes));
    }
}
