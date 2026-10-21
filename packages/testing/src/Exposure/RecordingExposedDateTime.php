<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use DateTimeImmutable;
use DateTimeInterface;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exposure\CalendarStateFormat;
use Stewart\Contracts\Exposure\Command\DateTimeCommand;
use Stewart\Contracts\Exposure\DateTimeConfig;
use Stewart\Contracts\Exposure\ExposedDateTime;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\State\EventContext;

/** @extends RecordingExposedEntity<DateTimeConfig> */
final class RecordingExposedDateTime extends RecordingExposedEntity implements ExposedDateTime
{
    public function getConfig(): DateTimeConfig
    {
        return $this->config;
    }

    public function updateConfig(DateTimeConfig $config): void
    {
        $this->recordConfig($config);
    }

    public function getValue(): ?DateTimeImmutable
    {
        $value = $this->findStateValue();

        return \is_string($value) ? CalendarStateFormat::parseDateTime($value) : null;
    }

    public function setValue(?DateTimeInterface $value, ?array $attributes = null): void
    {
        $this->recordChange(new ExposedStateChange(new ExposedState($value === null ? null : CalendarStateFormat::formatDateTime($value)), $attributes));
    }

    public function watchCommands(): EventStream
    {
        /** @var EventStream<DateTimeCommand> */
        return $this->watchPushedCommands();
    }

    /** @throws CommandException */
    public function pushCommand(DateTimeCommand $command): void
    {
        $this->deliverCommand($command);
    }

    /** @throws CommandException */
    public function pushValue(DateTimeImmutable $value, ?EventContext $context = null): void
    {
        $this->pushCommand(new DateTimeCommand($value, $context ?? EventContext::unknown()));
    }
}
