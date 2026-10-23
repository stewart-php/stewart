<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console\Check;

use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppCatalogResolver;
use Stewart\Runtime\App\AppDiscovery;
use Stewart\Runtime\App\AppInstanceResolver;
use Stewart\Runtime\App\AppSelection;
use Stewart\Runtime\App\GeneratedRoots;
use Stewart\Runtime\App\UnloadableAppFile;
use Stewart\Runtime\Broker\AppPlacement;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Config\HttpConfig;
use Stewart\Runtime\Container\AppContainerBlueprint;
use Stewart\Runtime\Container\AppContainerBuilder;
use Stewart\Runtime\Container\Collection\AppBuildFailureCollection;

final readonly class ProjectCheck
{
    public function __construct(
        private ConfigLoader $config,
        private AppDiscovery $discovery,
        private AppInstanceResolver $instances,
        private AppCatalogResolver $catalogs,
        private AppPlacement $placement,
        private AppContainerBuilder $containers,
        private string $userServicesFile,
    ) {}

    // Errors come in the order the Git deploy gate reports them: its first line is the rejection reason.
    public function checkProject(?string $configFile, AppSelection $selection = new AppSelection()): ProjectCheckResult
    {
        try {
            $config = $this->config->loadConfig($configFile);
            $config->requireHomeAssistant();
            $generated = GeneratedRoots::fromNamespace($config->codegen->namespace->value);
            $discovered = $this->instances->resolveInstances($this->discovery->discover(), $config->apps);
            $catalog = $this->catalogs->resolveCatalog($discovered, $config->apps, $config->workers, $selection);
        } catch (StewartException $e) {
            return ProjectCheckResult::failed([$e->getMessage()]);
        }

        if (!$discovered->unloadable->isEmpty()) {
            return ProjectCheckResult::failed($discovered->unloadable->mapToList($this->describeUnloadableFile(...)));
        }

        $workerSlots = $this->placement->planWorkerSlots($catalog->enabled, $config);

        try {
            $failures = $this->findAppBuildFailures($workerSlots, AppContainerBlueprint::fromProjectConfig($config, $generated, $catalog->knownIds, $this->userServicesFile));
        } catch (StewartException $e) {
            return ProjectCheckResult::failed([$e->getMessage()]);
        }

        $errors = $this->listBuildErrorsInAppOrder($catalog, $failures);

        return $errors === []
            ? ProjectCheckResult::passed($discovered, $catalog, $workerSlots, $config->codegen, $this->findHttpListenerError($config->http))
            : ProjectCheckResult::failed($errors);
    }

    // Only a notice for check: CI checks a project without its secrets, which the daemon gets at deploy time.
    private function findHttpListenerError(HttpConfig $http): ?string
    {
        try {
            $http->requireValidListeners();
        } catch (StewartException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** @throws StewartException */
    private function findAppBuildFailures(WorkerSlotCollection $workerSlots, AppContainerBlueprint $blueprint): AppBuildFailureCollection
    {
        $failures = [];

        foreach ($workerSlots as $slot) {
            foreach ($this->containers->findAppBuildFailures($slot->listWorkerApps(), $blueprint, $slot->workerId) as $failure) {
                $failures[] = $failure;
            }
        }

        return AppBuildFailureCollection::keyedByAppId($failures);
    }

    /** @return list<string> */
    private function listBuildErrorsInAppOrder(AppCatalog $catalog, AppBuildFailureCollection $failures): array
    {
        $errors = [];

        foreach ($catalog->enabled as $app) {
            $failure = $failures->find($app->id);

            if ($failure !== null) {
                $errors[] = $failure->error->getMessage();
            }
        }

        return $errors;
    }

    private function describeUnloadableFile(UnloadableAppFile $file): string
    {
        $cause = str_replace(["\r", "\n"], ' ', $file->cause->getMessage());

        return \sprintf('%s failed to load: %s', $file->path, $cause);
    }
}
