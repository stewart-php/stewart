<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exposure\Command\TextCommand;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\ExposedText;
use Stewart\Contracts\Exposure\TextConfig;
use Stewart\Contracts\State\EventContext;

/** @extends RecordingExposedEntity<TextConfig> */
final class RecordingExposedText extends RecordingExposedEntity implements ExposedText
{
    public function getConfig(): TextConfig
    {
        return $this->config;
    }

    public function updateConfig(TextConfig $config): void
    {
        $this->recordConfig($config);
    }

    public function getValue(): ?string
    {
        $value = $this->findStateValue();

        return \is_string($value) ? $value : null;
    }

    public function setValue(?string $value, ?array $attributes = null): void
    {
        $this->recordChange(new ExposedStateChange(new ExposedState($value), $attributes));
    }

    public function watchCommands(): EventStream
    {
        /** @var EventStream<TextCommand> */
        return $this->watchPushedCommands();
    }

    /** @throws CommandException */
    public function pushCommand(TextCommand $command): void
    {
        $this->deliverCommand($command);
    }

    /** @throws CommandException */
    public function pushValue(string $value, ?EventContext $context = null): void
    {
        $this->pushCommand(new TextCommand($value, $context ?? EventContext::unknown()));
    }
}
