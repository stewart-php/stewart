<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure\Action;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\ExposedEntityKey;

interface ExposedAction
{
    public function getKey(): ExposedEntityKey;

    public function getConfig(): ActionConfig;

    /** @throws ExposureException */
    public function updateConfig(ActionConfig $config): void;

    /** @return EventStream<ActionCall> */
    public function watchCalls(): EventStream;

    /** @throws ExposureException */
    public function remove(): void;
}
