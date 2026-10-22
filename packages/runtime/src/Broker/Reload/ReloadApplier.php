<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\CatalogWarnings;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Broker\AppTopology;
use Stewart\Runtime\Broker\DaemonLaunchOptions;
use Stewart\Runtime\Broker\Exposure\ExposureLink;
use Stewart\Runtime\Broker\Exposure\ExposureReconciler;
use Stewart\Runtime\Broker\GeneratedCodeDrift;
use Stewart\Runtime\Broker\WorkerPool;
use Stewart\Runtime\Broker\WorkerPoolListener;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Logging\LogVerbosityPolicy;

final readonly class ReloadApplier
{
    public function __construct(
        private AppTopology $topology,
        private AppPauseService $pauses,
        private AppMetrics $metrics,
        private ExposureLink $exposures,
        private ExposureReconciler $reconciler,
        private WorkerPool $pool,
        private WorkerPoolListener $workerEvents,
        private CatalogWarnings $catalogWarnings,
        private ConfigLoader $configs,
        private DaemonLaunchOptions $launch,
        private LogVerbosityPolicy $verbosity,
        private GeneratedCodeDrift $drift,
        private LoggerInterface $logger,
    ) {}

    public function applyReload(ReloadPlan $plan): void
    {
        $previous = $this->topology->getCatalog();
        $this->topology->replaceTopology($plan->catalog, $plan->workerSlots);
        $this->pauses->followReloadedCatalog($previous);
        $this->metrics->replaceAppTotals($plan->workerSlots);
        $this->verbosity->replaceAppVerbosities($plan->catalog->enabled->listVerbositiesByAppId());
        $this->drift->replaceGeneratedManifest($plan->generated);
        $this->drift->recheckGeneratedCode();

        foreach ($previous->enabled->filter(static fn(AppDefinition $app): bool => $plan->catalog->enabled->find($app->id) === null) as $removed) {
            $this->exposures->forgetExposuresOfApp($removed->id);
        }

        $this->reconciler->restartAfterReload();
        $this->catalogWarnings->logCatalogWarnings($plan->discovered, $plan->catalog, $this->configs->locateConfigFile($this->launch->configFile), $this->logger);
        $this->pool->applyWorkerSlots($plan->workerSlots, $this->workerEvents);
    }
}
