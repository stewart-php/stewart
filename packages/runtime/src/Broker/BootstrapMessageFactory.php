<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use DateTimeZone;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\Config\PersistenceConfig;
use Stewart\Runtime\Config\StewartConfig;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Runtime\Ipc\Message\Bootstrap;
use Stewart\Runtime\Ipc\StoreSettings;
use Stewart\Runtime\Ipc\Wire\AppIdsFragment;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Ipc\Wire\WorkerAppsFragment;
use Stewart\Runtime\Ipc\WorkerSettings;

final readonly class BootstrapMessageFactory
{
    // Headroom so a worker times out a call only after Home Assistant has.
    private const int CALL_TIMEOUT_GRACE_SECONDS = 5;

    private WorkerSettings $workerSettings;

    private ?StoreSettings $storeSettings;

    /** @throws ConfigurationException */
    public function __construct(
        StewartConfig $config,
        private AppCatalog $apps,
        string $userServicesFile,
    ) {
        $this->workerSettings = new WorkerSettings(
            logLevel: $config->logLevel,
            callTimeout: $config->requireHomeAssistant()->commandTimeout->plus(Duration::seconds(self::CALL_TIMEOUT_GRACE_SECONDS)),
            shutdownGrace: $config->shutdownGrace,
            subscriptionBuffer: $config->subscriptionBuffer,
            initializeTimeout: $config->supervision->initializeTimeout->findDuration(),
            servicesFile: is_file($userServicesFile) ? $userServicesFile : null,
            generatedNamespace: $config->codegen->namespace->value,
        );
        $this->storeSettings = $config->persistence === null ? null : $this->buildStoreSettings($config->persistence);
    }

    public function createBootstrapMessage(WorkerHandle $handle, DateTimeZone $timeZone): Bootstrap
    {
        return new Bootstrap(
            protocol: IpcCodec::PROTOCOL_VERSION,
            workerId: $handle->id,
            timeZone: $timeZone->getName(),
            apps: WorkerAppsFragment::fromCollection($handle->slot->listWorkerApps()),
            settings: $this->workerSettings,
            store: $this->storeSettings,
            knownAppIds: AppIdsFragment::fromCollection($this->apps->knownIds),
        );
    }

    private function buildStoreSettings(PersistenceConfig $persistence): StoreSettings
    {
        return new StoreSettings(
            dsn: $persistence->url->reveal(),
            prefix: $persistence->prefix->value,
            timeout: $persistence->timeout,
            recoveryInterval: $persistence->recoveryInterval,
        );
    }
}
