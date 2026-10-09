<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\Command\NumberCommand;

/** @phpstan-import-type ExposedAttributes from ExposedEntity */
interface ExposedNumber extends ExposedEntity
{
    public function getConfig(): NumberConfig;

    /** @throws ExposureException */
    public function updateConfig(NumberConfig $config): void;

    public function getValue(): int|float|null;

    /**
     * @param ExposedAttributes|null $attributes
     * @throws ExposureException
     */
    public function setValue(int|float|null $value, ?array $attributes = null): void;

    /** @return EventStream<NumberCommand> */
    public function watchCommands(): EventStream;
}
