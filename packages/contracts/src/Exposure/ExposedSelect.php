<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\Command\SelectCommand;

/** @phpstan-import-type ExposedAttributes from ExposedEntity */
interface ExposedSelect extends ExposedEntity
{
    public function getOption(): ?string;

    /**
     * @param ExposedAttributes|null $attributes
     * @throws ExposureException
     */
    public function setOption(?string $option, ?array $attributes = null): void;

    /** @return EventStream<SelectCommand> */
    public function watchCommands(): EventStream;
}
