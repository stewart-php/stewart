<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\Command\ExposedCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\ExposureConfig;
use Stewart\Runtime\Model\ResourceScope;

/** @template TConfig of ExposureConfig */
abstract class WorkerExposure implements ExposedHandle
{
    private bool $released = false;

    /** @param TConfig $config */
    public function __construct(
        private readonly ExposureChannel $channel,
        private readonly ResourceScope $scope,
        private readonly ExposedEntityKey $key,
        private ExposureConfig $config,
    ) {}

    public function getKey(): ExposedEntityKey
    {
        return $this->key;
    }

    public function remove(): void
    {
        $this->assertNotReleased();
        $this->channel->requester->requestRemoval($this->scope, $this->key);
        $this->channel->handles->forgetHandle($this->scope, $this->key);
        $this->released = true;
    }

    public function markReleased(): void
    {
        $this->released = true;
    }

    /** @return TConfig */
    protected function findConfig(): ExposureConfig
    {
        return $this->config;
    }

    /** @return EventStream<ExposedCommand> */
    protected function watchOwnCommands(): EventStream
    {
        return $this->channel->commandStreams->watchCommands($this->scope, $this->key);
    }

    /**
     * @param TConfig $config
     * @throws ExposureException
     */
    protected function sendReconfiguration(ExposureConfig $config): void
    {
        $this->assertNotReleased();
        $snapshot = $this->channel->requester->requestReconfiguration($this->scope, $this->key, $config);
        $this->config = $config;

        if ($snapshot !== null) {
            $this->applySnapshot($snapshot);
        }
    }

    /** @throws ExposureException */
    protected function sendUpdate(ExposedStateChange $change): void
    {
        $this->assertNotReleased();
        $this->channel->requester->requestUpdate($this->scope, $this->key, $change);
    }

    /** @throws ExposureException */
    private function assertNotReleased(): void
    {
        if ($this->released) {
            throw ExposureException::removed($this->key);
        }
    }
}
