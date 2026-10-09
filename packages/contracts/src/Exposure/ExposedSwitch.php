<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\Command\SwitchCommand;

/** @phpstan-import-type ExposedAttributes from ExposedEntity */
interface ExposedSwitch extends ExposedEntity
{
    public function getValue(): ?bool;

    /**
     * @param ExposedAttributes|null $attributes
     * @throws ExposureException
     */
    public function setOn(?array $attributes = null): void;

    /**
     * @param ExposedAttributes|null $attributes
     * @throws ExposureException
     */
    public function setOff(?array $attributes = null): void;

    /** @return EventStream<SwitchCommand> */
    public function watchCommands(): EventStream;
}
