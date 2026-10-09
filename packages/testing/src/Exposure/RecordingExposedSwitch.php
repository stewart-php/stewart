<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\ExposedSwitch;
use Stewart\Contracts\Exposure\SwitchConfig;

final class RecordingExposedSwitch extends RecordingExposedEntity implements ExposedSwitch
{
    public function __construct(
        ExposedEntityKey $key,
        public private(set) SwitchConfig $config,
        ?ExposedEntitySnapshot $seeded,
    ) {
        parent::__construct($key, $seeded);
    }

    public function getConfig(): SwitchConfig
    {
        return $this->config;
    }

    public function updateConfig(SwitchConfig $config): void
    {
        $this->assertNotRemoved();
        $this->config = $config;
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

    public function watchCommands(): EventStream
    {
        /** @var EventStream<SwitchCommand> */
        return $this->watchPushedCommands();
    }

    /** @throws CommandException */
    public function pushCommand(SwitchCommand $command): void
    {
        $this->deliverCommand($command);
    }
}
