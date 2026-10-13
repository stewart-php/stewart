<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exposure\ButtonConfig;
use Stewart\Contracts\Exposure\Command\ButtonPress;
use Stewart\Contracts\Exposure\ExposedButton;

/** @extends RecordingExposedEntity<ButtonConfig> */
final class RecordingExposedButton extends RecordingExposedEntity implements ExposedButton
{
    public function getConfig(): ButtonConfig
    {
        return $this->config;
    }

    public function updateConfig(ButtonConfig $config): void
    {
        $this->recordConfig($config);
    }

    public function watchCommands(): EventStream
    {
        /** @var EventStream<ButtonPress> */
        return $this->watchPushedCommands();
    }

    /** @throws CommandException */
    public function pushCommand(ButtonPress $command): void
    {
        $this->deliverCommand($command);
    }
}
