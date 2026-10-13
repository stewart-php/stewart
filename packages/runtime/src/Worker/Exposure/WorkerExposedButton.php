<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exposure\ButtonConfig;
use Stewart\Contracts\Exposure\Command\ButtonPress;
use Stewart\Contracts\Exposure\ExposedButton;

/** @extends WorkerExposedEntity<ButtonConfig> */
final class WorkerExposedButton extends WorkerExposedEntity implements ExposedButton
{
    public function getConfig(): ButtonConfig
    {
        return $this->findConfig();
    }

    public function updateConfig(ButtonConfig $config): void
    {
        $this->sendReconfiguration($config);
    }

    public function watchCommands(): EventStream
    {
        /** @var EventStream<ButtonPress> */
        return $this->watchOwnCommands();
    }
}
