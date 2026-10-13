<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exposure\Action\ActionCall;
use Stewart\Contracts\Exposure\Action\ActionConfig;
use Stewart\Contracts\Exposure\Action\ExposedAction;
use Stewart\Contracts\State\EventContext;

/** @extends RecordingExposure<ActionConfig> */
final class RecordingExposedAction extends RecordingExposure implements ExposedAction
{
    public function getConfig(): ActionConfig
    {
        return $this->config;
    }

    public function updateConfig(ActionConfig $config): void
    {
        $this->recordConfig($config);
    }

    public function watchCalls(): EventStream
    {
        /** @var EventStream<ActionCall> */
        return $this->watchPushedCommands();
    }

    /**
     * @param array<string, mixed> $data
     * @throws CommandException
     */
    public function pushCall(array $data): void
    {
        $this->deliverCommand(new ActionCall($data, EventContext::unknown()));
    }
}
