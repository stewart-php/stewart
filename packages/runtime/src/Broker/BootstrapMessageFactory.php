<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\Time\Duration;
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

    private Duration $callTimeout;

    private ?StoreSettings $storeSettings;

    /** @throws ConfigurationException */
    public function __construct(
        private StewartConfig $config,
        private AppTopology $topology,
        private AppPauseRegistry $pausedApps,
        private string $userServicesFile,
    ) {
        $this->callTimeout = $config->requireHomeAssistant()->commandTimeout->plus(Duration::seconds(self::CALL_TIMEOUT_GRACE_SECONDS));
        $this->storeSettings = $this->buildStoreSettings($config->persistence);
    }

    public function createBootstrapMessage(WorkerHandle $handle, HaSession $session): Bootstrap
    {
        return new Bootstrap(
            protocol: IpcCodec::PROTOCOL_VERSION,
            workerId: $handle->id,
            timeZone: $session->getTimeZone()->getName(),
            location: $session->getLocation(),
            apps: WorkerAppsFragment::fromCollection($handle->slot->listWorkerApps()),
            settings: $this->buildWorkerSettings(),
            store: $this->storeSettings,
            knownAppIds: AppIdsFragment::fromCollection($this->topology->getCatalog()->knownIds),
            pausedAppIds: AppIdsFragment::fromCollection($this->pausedApps->listPausedAppIds()),
            haUserId: $session->getHaUserId(),
        );
    }

    // services.php is looked for at every spawn, so one created after the start is used once the workers reload.
    private function buildWorkerSettings(): WorkerSettings
    {
        return new WorkerSettings(
            logLevel: $this->config->logLevel,
            callTimeout: $this->callTimeout,
            shutdownGrace: $this->config->shutdownGrace,
            subscriptionBuffer: $this->config->subscriptionBuffer,
            initializeTimeout: $this->config->supervision->initializeTimeout->findDuration(),
            servicesFile: is_file($this->userServicesFile) ? $this->userServicesFile : null,
            generatedNamespace: $this->config->codegen->namespace->value,
            mqttEnabled: $this->config->mqtt !== null,
        );
    }

    private function buildStoreSettings(PersistenceConfig $persistence): ?StoreSettings
    {
        $url = $persistence->url;

        return $url === null ? null : new StoreSettings(
            dsn: $url->reveal(),
            prefix: $persistence->prefix->value,
            timeout: $persistence->timeout,
            recoveryInterval: $persistence->recoveryInterval,
        );
    }
}
