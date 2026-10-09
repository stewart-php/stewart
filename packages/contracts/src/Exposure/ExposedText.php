<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\Command\TextCommand;

/** @phpstan-import-type ExposedAttributes from ExposedEntity */
interface ExposedText extends ExposedEntity
{
    public function getValue(): ?string;

    /**
     * @param ExposedAttributes|null $attributes
     * @throws ExposureException
     */
    public function setValue(?string $value, ?array $attributes = null): void;

    /** @return EventStream<TextCommand> */
    public function watchCommands(): EventStream;
}
