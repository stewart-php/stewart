<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Exposure;

use Stewart\Client\Component\ExposedEntityDefinition;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\ExposeEntityRequest;
use Stewart\Runtime\Ipc\Message\ExposeEntityResult;
use Stewart\Runtime\Ipc\Message\ExposureAcknowledged;
use Stewart\Runtime\Ipc\Message\ReconfigureExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\RemoveExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\UpdateExposedEntityRequest;
use Stewart\Runtime\Model\ResourceScope;

final readonly class ExposureProxy
{
    public function __construct(
        private ExposureLink $exposures,
        private HaCallSlots $slots,
    ) {}

    public function forwardExpose(WorkerHandle $handle, ExposeEntityRequest $request): void
    {
        $this->slots->runCounted($handle, $request, function () use ($handle, $request): ExposeEntityResult {
            $appId = $this->requireAppId($request->scope, $request->key);
            $definition = ExposedEntityDefinition::fromConfig($request->config, $request->device);
            $snapshot = $this->exposures->exposeEntity($handle->id, $appId, $request->key, $definition, $request->change);

            return new ExposeEntityResult($request->correlationId, $snapshot);
        });
    }

    public function forwardReconfigure(WorkerHandle $handle, ReconfigureExposedEntityRequest $request): void
    {
        $this->slots->runCounted($handle, $request, function () use ($request): ExposeEntityResult {
            $appId = $this->requireAppId($request->scope, $request->key);
            $snapshot = $this->exposures->reconfigureEntity($appId, $request->key, ExposedEntityDefinition::fromConfig($request->config, null));

            return new ExposeEntityResult($request->correlationId, $snapshot);
        });
    }

    public function forwardUpdate(WorkerHandle $handle, UpdateExposedEntityRequest $request): void
    {
        $this->slots->runCounted($handle, $request, function () use ($request): ExposureAcknowledged {
            $this->exposures->updateEntity($this->requireAppId($request->scope, $request->key), $request->key, $request->change);

            return new ExposureAcknowledged($request->correlationId);
        });
    }

    public function forwardRemove(WorkerHandle $handle, RemoveExposedEntityRequest $request): void
    {
        $this->slots->runCounted($handle, $request, function () use ($request): ExposureAcknowledged {
            $this->exposures->removeEntity($this->requireAppId($request->scope, $request->key), $request->key);

            return new ExposureAcknowledged($request->correlationId);
        });
    }

    /** @throws ExposureException */
    private function requireAppId(ResourceScope $scope, ExposedEntityKey $key): AppId
    {
        return $scope->appId ?? throw ExposureException::outsideApp($key);
    }
}
