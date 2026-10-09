<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use DateTimeImmutable;
use DateTimeInterface;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exposure\CalendarStateFormat;
use Stewart\Contracts\Exposure\Command\DateCommand;
use Stewart\Contracts\Exposure\DateConfig;
use Stewart\Contracts\Exposure\ExposedDate;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;

final class RecordingExposedDate extends RecordingExposedEntity implements ExposedDate
{
    public function __construct(
        ExposedEntityKey $key,
        public private(set) DateConfig $config,
        ?ExposedEntitySnapshot $seeded,
    ) {
        parent::__construct($key, $seeded);
    }

    public function getConfig(): DateConfig
    {
        return $this->config;
    }

    public function updateConfig(DateConfig $config): void
    {
        $this->assertNotRemoved();
        $this->config = $config;
    }

    public function getValue(): ?DateTimeImmutable
    {
        $value = $this->findStateValue();

        return \is_string($value) ? CalendarStateFormat::parseDate($value) : null;
    }

    public function setValue(?DateTimeInterface $value, ?array $attributes = null): void
    {
        $this->recordChange(new ExposedStateChange(new ExposedState($value === null ? null : CalendarStateFormat::formatDate($value)), $attributes));
    }

    public function watchCommands(): EventStream
    {
        /** @var EventStream<DateCommand> */
        return $this->watchPushedCommands();
    }

    /** @throws CommandException */
    public function pushCommand(DateCommand $command): void
    {
        $this->deliverCommand($command);
    }
}
