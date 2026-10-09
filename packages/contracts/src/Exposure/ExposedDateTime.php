<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use DateTimeImmutable;
use DateTimeInterface;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\Command\DateTimeCommand;

/** @phpstan-import-type ExposedAttributes from ExposedEntity */
interface ExposedDateTime extends ExposedEntity
{
    public function getValue(): ?DateTimeImmutable;

    /**
     * @param ExposedAttributes|null $attributes
     * @throws ExposureException
     */
    public function setValue(?DateTimeInterface $value, ?array $attributes = null): void;

    /** @return EventStream<DateTimeCommand> */
    public function watchCommands(): EventStream;
}
