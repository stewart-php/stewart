<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\Command\TimeCommand;
use Stewart\Contracts\Schedule\TimeOfDay;

/** @phpstan-import-type ExposedAttributes from ExposedEntity */
interface ExposedTime extends ExposedEntity
{
    public function getConfig(): TimeConfig;

    /** @throws ExposureException */
    public function updateConfig(TimeConfig $config): void;

    public function getValue(): ?TimeOfDay;

    /**
     * @param ExposedAttributes|null $attributes
     * @throws ExposureException
     */
    public function setValue(?TimeOfDay $value, ?array $attributes = null): void;

    /** @return EventStream<TimeCommand> */
    public function watchCommands(): EventStream;
}
