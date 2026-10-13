<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\Command\ExposedCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposureConfig;
use Stewart\Support\Stream\OperatorStream;
use Stewart\Testing\Stream\PushSource;
use Stewart\Testing\Time\ManualTimers;

/** @template TConfig of ExposureConfig */
abstract class RecordingExposure
{
    public private(set) bool $removed = false;

    /** @var PushSource<ExposedCommand> */
    private readonly PushSource $commands;

    /** @param TConfig $config */
    public function __construct(
        private readonly ExposedEntityKey $key,
        public private(set) ExposureConfig $config,
        private readonly ManualTimers $timers,
    ) {
        $this->commands = new PushSource($timers->handlerFibers);
    }

    public function getKey(): ExposedEntityKey
    {
        return $this->key;
    }

    public function cancelCommandSubscriptions(): void
    {
        $this->commands->cancelAttachments();
    }

    public function remove(): void
    {
        $this->assertNotRemoved();
        $this->removed = true;
    }

    /**
     * @param TConfig $config
     * @throws ExposureException
     */
    protected function recordConfig(ExposureConfig $config): void
    {
        $this->assertNotRemoved();
        $this->config = $config;
    }

    /** @return EventStream<ExposedCommand> */
    protected function watchPushedCommands(): EventStream
    {
        return new OperatorStream($this->commands, $this->timers);
    }

    /** @throws CommandException */
    protected function deliverCommand(ExposedCommand $command): void
    {
        $this->commands->push($command);
    }

    /** @throws ExposureException */
    protected function assertNotRemoved(): void
    {
        if ($this->removed) {
            throw ExposureException::removed($this->key);
        }
    }
}
