<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\App\AppCatalogResolver;
use Stewart\Runtime\Broker\AppPlacement;
use Stewart\Runtime\Broker\DaemonLaunchOptions;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Config\StewartConfig;
use Stewart\Runtime\Exception\BrokerError;
use Stewart\Runtime\Exception\BrokerException;

final readonly class ReloadPlanner
{
    public function __construct(
        private ConfigLoader $configs,
        private AppCatalogResolver $catalogs,
        private AppPlacement $placement,
        private ConfigChangeClassifier $changes,
        private DaemonLaunchOptions $launch,
        private StewartConfig $runningConfig,
    ) {}

    /** @throws StewartException */
    public function planReload(ReloadCheckReport $report): ReloadPlan
    {
        $configFile = $this->launch->configFile;

        // The child checked one version of the file; applying another would skip the check.
        if ($this->configs->fingerprintConfigFile($configFile) !== $report->configFingerprint) {
            throw BrokerException::createForReason(BrokerError::ReloadConfigChanged, path: $this->configs->locateConfigFile($configFile));
        }

        $config = $this->configs->loadConfig($configFile, $this->launch->listConfigOverrides());
        $discovered = $report->restoreDiscovery();
        $catalog = $this->catalogs->resolveCatalog($discovered, $config->apps, $config->workers, $this->launch->selection);

        return new ReloadPlan(
            $discovered,
            $catalog,
            $this->placement->planWorkerSlots($catalog->enabled, $config),
            $this->changes->listSettingsNeedingRestart($this->runningConfig, $config),
        );
    }
}
