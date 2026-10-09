<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\Command\ButtonPress;

interface ExposedButton extends ExposedEntity
{
    public function getConfig(): ButtonConfig;

    /** @throws ExposureException */
    public function updateConfig(ButtonConfig $config): void;

    /** @return EventStream<ButtonPress> */
    public function watchCommands(): EventStream;
}
