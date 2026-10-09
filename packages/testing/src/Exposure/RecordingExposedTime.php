<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exposure\CalendarStateFormat;
use Stewart\Contracts\Exposure\Command\TimeCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\ExposedTime;
use Stewart\Contracts\Exposure\TimeConfig;
use Stewart\Contracts\Schedule\TimeOfDay;

final class RecordingExposedTime extends RecordingExposedEntity implements ExposedTime
{
    public function __construct(
        ExposedEntityKey $key,
        public readonly TimeConfig $config,
        ?ExposedEntitySnapshot $seeded,
    ) {
        parent::__construct($key, $seeded);
    }

    public function getValue(): ?TimeOfDay
    {
        $value = $this->findStateValue();

        return \is_string($value) ? CalendarStateFormat::parseTime($value) : null;
    }

    public function setValue(?TimeOfDay $value, ?array $attributes = null): void
    {
        $this->recordChange(new ExposedStateChange(new ExposedState($value === null ? null : CalendarStateFormat::formatTime($value)), $attributes));
    }

    public function watchCommands(): EventStream
    {
        /** @var EventStream<TimeCommand> */
        return $this->watchPushedCommands();
    }

    /** @throws CommandException */
    public function pushCommand(TimeCommand $command): void
    {
        $this->deliverCommand($command);
    }
}
