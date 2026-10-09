<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use DateTimeImmutable;
use DateTimeInterface;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\Command\DateCommand;

/** @phpstan-import-type ExposedAttributes from ExposedEntity */
interface ExposedDate extends ExposedEntity
{
    public function getConfig(): DateConfig;

    /** @throws ExposureException */
    public function updateConfig(DateConfig $config): void;

    public function getValue(): ?DateTimeImmutable;

    /**
     * @param ExposedAttributes|null $attributes
     * @throws ExposureException
     */
    public function setValue(?DateTimeInterface $value, ?array $attributes = null): void;

    /** @return EventStream<DateCommand> */
    public function watchCommands(): EventStream;
}
