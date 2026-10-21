<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exposure\Command\SelectCommand;
use Stewart\Contracts\Exposure\ExposedSelect;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\SelectConfig;
use Stewart\Contracts\State\EventContext;

/** @extends RecordingExposedEntity<SelectConfig> */
final class RecordingExposedSelect extends RecordingExposedEntity implements ExposedSelect
{
    public function getConfig(): SelectConfig
    {
        return $this->config;
    }

    public function updateConfig(SelectConfig $config): void
    {
        $this->recordConfig($config);
    }

    public function getOption(): ?string
    {
        $value = $this->findStateValue();

        return \is_string($value) ? $value : null;
    }

    public function setOption(?string $option, ?array $attributes = null): void
    {
        $this->recordChange(new ExposedStateChange(new ExposedState($option), $attributes));
    }

    public function watchCommands(): EventStream
    {
        /** @var EventStream<SelectCommand> */
        return $this->watchPushedCommands();
    }

    /** @throws CommandException */
    public function pushCommand(SelectCommand $command): void
    {
        $this->deliverCommand($command);
    }

    /** @throws CommandException */
    public function pushOption(string $option, ?EventContext $context = null): void
    {
        $this->pushCommand(new SelectCommand($option, $context ?? EventContext::unknown()));
    }
}
