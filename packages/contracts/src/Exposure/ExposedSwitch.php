<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\Exception\ExposureException;

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
}
