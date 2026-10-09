<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exposure\CalendarStateFormat;
use Stewart\Contracts\Exposure\Command\TimeCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\ExposedTime;
use Stewart\Contracts\Exposure\TimeConfig;
use Stewart\Contracts\Schedule\TimeOfDay;
use Stewart\Runtime\Model\ResourceScope;

/** @extends WorkerExposedEntity<TimeConfig> */
final class WorkerExposedTime extends WorkerExposedEntity implements ExposedTime
{
    public function __construct(
        ExposureRequester $requester,
        ExposedHandleRegistry $handles,
        ResourceScope $scope,
        ExposedEntityKey $key,
        TimeConfig $config,
        private readonly ExposedCommandStreams $commandStreams,
    ) {
        parent::__construct($requester, $handles, $scope, $key, $config);
    }

    public function getConfig(): TimeConfig
    {
        return $this->findConfig();
    }

    public function updateConfig(TimeConfig $config): void
    {
        $this->sendReconfiguration($config);
    }

    public function watchCommands(): EventStream
    {
        /** @var EventStream<TimeCommand> */
        return $this->commandStreams->watchCommands($this->scope, $this->key);
    }

    public function getValue(): ?TimeOfDay
    {
        $value = $this->findStateValue();

        return \is_string($value) ? CalendarStateFormat::parseTime($value) : null;
    }

    public function setValue(?TimeOfDay $value, ?array $attributes = null): void
    {
        $this->sendChange(new ExposedStateChange(new ExposedState($value === null ? null : CalendarStateFormat::formatTime($value)), $attributes));
    }
}
