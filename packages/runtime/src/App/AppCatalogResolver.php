<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Config\Collection\AppOverrideCollection;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Runtime\Model\AppVerbosity;

final readonly class AppCatalogResolver
{
    /** @throws ConfigurationException */
    public function resolveCatalog(DiscoveryResult $discovered, AppOverrideCollection $overrides, int $workers, AppSelection $selection = new AppSelection()): AppCatalog
    {
        $knownIds = $discovered->apps->listAppIds();
        $unmatchedOverrideIds = [];

        $this->assertSelectedAppsExist('--only', $selection->onlyIds, $knownIds);
        $this->assertSelectedAppsExist('--trace', $selection->tracedIds, $knownIds);

        foreach ($overrides as $override) {
            if ($knownIds->findFirstWhere(static fn(AppId $known): bool => $known->collidesWith($override->id)) === null) {
                $unmatchedOverrideIds[] = $override->id;
            }
        }

        $enabled = [];

        foreach ($discovered->apps as $app) {
            $override = $overrides->findMatchingApp($app->id);

            if (!$selection->admitsApp($app->id, $override === null || $override->enabled)) {
                continue;
            }

            $worker = $override?->worker;

            if ($worker !== null && $workers > 0 && $worker >= $workers) {
                throw ConfigurationException::workerIndexOutOfRange($app->id, $worker, $workers);
            }

            $enabled[] = new AppDefinition(
                id: $app->id,
                class: $app->class,
                options: $override === null ? [] : $override->options,
                worker: $worker,
                startsPaused: $override !== null && $override->paused,
                verbosity: new AppVerbosity($override?->logLevel, ($override !== null && $override->trace) || $selection->tracesApp($app->id)),
            );
        }

        return new AppCatalog(AppDefinitionCollection::keyedByAppId($enabled), $knownIds, AppIdCollection::fromList($unmatchedOverrideIds));
    }

    /** @throws ConfigurationException */
    private function assertSelectedAppsExist(string $option, ?AppIdCollection $selectedIds, AppIdCollection $knownIds): void
    {
        foreach ($selectedIds ?? [] as $selectedId) {
            if (!$knownIds->containsId($selectedId)) {
                throw ConfigurationException::appSelectionUnknown($option, $selectedId, $knownIds);
            }
        }
    }
}
