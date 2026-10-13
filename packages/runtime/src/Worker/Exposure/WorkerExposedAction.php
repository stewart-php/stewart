<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exposure\Action\ActionCall;
use Stewart\Contracts\Exposure\Action\ActionConfig;
use Stewart\Contracts\Exposure\Action\ExposedAction;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;

/** @extends WorkerExposure<ActionConfig> */
final class WorkerExposedAction extends WorkerExposure implements ExposedAction
{
    public function getConfig(): ActionConfig
    {
        return $this->findConfig();
    }

    public function updateConfig(ActionConfig $config): void
    {
        $this->sendReconfiguration($config);
    }

    public function watchCalls(): EventStream
    {
        /** @var EventStream<ActionCall> */
        return $this->watchOwnCommands();
    }

    // An action has no entity in Home Assistant, so there is no state to track.
    public function applySnapshot(ExposedEntitySnapshot $snapshot): void {}

    public function recordCommandedState(ExposedState $state): void {}
}
