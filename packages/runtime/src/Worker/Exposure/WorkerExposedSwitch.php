<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\ExposedSwitch;
use Stewart\Contracts\Exposure\SwitchConfig;

/** @extends WorkerExposedEntity<SwitchConfig> */
final class WorkerExposedSwitch extends WorkerExposedEntity implements ExposedSwitch
{
    public function getConfig(): SwitchConfig
    {
        return $this->findConfig();
    }

    public function updateConfig(SwitchConfig $config): void
    {
        $this->sendReconfiguration($config);
    }

    public function watchCommands(): EventStream
    {
        /** @var EventStream<SwitchCommand> */
        return $this->watchOwnCommands();
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
