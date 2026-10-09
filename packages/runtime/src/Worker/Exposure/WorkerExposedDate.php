<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use DateTimeImmutable;
use DateTimeInterface;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exposure\CalendarStateFormat;
use Stewart\Contracts\Exposure\Command\DateCommand;
use Stewart\Contracts\Exposure\ExposedDate;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Runtime\Model\ResourceScope;

final class WorkerExposedDate extends WorkerExposedEntity implements ExposedDate
{
    public function __construct(
        ExposureRequester $requester,
        ExposedHandleRegistry $handles,
        ResourceScope $scope,
        ExposedEntityKey $key,
        private readonly ExposedCommandStreams $commandStreams,
    ) {
        parent::__construct($requester, $handles, $scope, $key);
    }

    public function watchCommands(): EventStream
    {
        /** @var EventStream<DateCommand> */
        return $this->commandStreams->watchCommands($this->scope, $this->key);
    }

    public function getValue(): ?DateTimeImmutable
    {
        $value = $this->findStateValue();

        return \is_string($value) ? CalendarStateFormat::parseDate($value) : null;
    }

    public function setValue(?DateTimeInterface $value, ?array $attributes = null): void
    {
        $this->sendChange(new ExposedStateChange(new ExposedState($value === null ? null : CalendarStateFormat::formatDate($value)), $attributes));
    }
}
