<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exposure\ButtonConfig;
use Stewart\Contracts\Exposure\Command\ButtonPress;
use Stewart\Contracts\Exposure\ExposedButton;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Runtime\Model\ResourceScope;

/** @extends WorkerExposedEntity<ButtonConfig> */
final class WorkerExposedButton extends WorkerExposedEntity implements ExposedButton
{
    public function __construct(
        ExposureRequester $requester,
        ExposedHandleRegistry $handles,
        ResourceScope $scope,
        ExposedEntityKey $key,
        ButtonConfig $config,
        private readonly ExposedCommandStreams $commandStreams,
    ) {
        parent::__construct($requester, $handles, $scope, $key, $config);
    }

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
        return $this->commandStreams->watchCommands($this->scope, $this->key);
    }
}
