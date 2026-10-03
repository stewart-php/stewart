<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Config\Collection\AppOverrideCollection;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class AppCatalogResolver
{
    /** @throws ConfigurationException */
    public function resolveCatalog(DiscoveryResult $discovered, AppOverrideCollection $overrides, int $workers, AppSelection $selection = new AppSelection()): AppCatalog
    {
        $knownIds = $discovered->apps->listAppIds();
        $unmatchedOverrideIds = [];

        foreach ($selection->onlyIds ?? [] as $selectedId) {
            if (!$knownIds->containsId($selectedId)) {
                throw ConfigurationException::appSelectionUnknown($selectedId, $knownIds);
            }
        }

        foreach ($overrides as $override) {
            if ($knownIds->containsId($override->id)) {
                continue;
            }

            // The app an unknown override names may live in a file that failed to load, which is reported instead.
            if (!$discovered->unloadable->isEmpty() && $this->findCollidingId($override->id, $knownIds) === null) {
                $unmatchedOverrideIds[] = $override->id;

                continue;
            }

            throw $this->createUnknownOverrideException($override->id, $knownIds);
        }

        $enabled = [];

        foreach ($discovered->apps as $app) {
            $override = $overrides->find($app->id);

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
            );
        }

        return new AppCatalog(AppDefinitionCollection::keyedByAppId($enabled), $knownIds, AppIdCollection::fromIds($unmatchedOverrideIds));
    }

    private function createUnknownOverrideException(AppId $overrideId, AppIdCollection $knownIds): ConfigurationException
    {
        $collision = $this->findCollidingId($overrideId, $knownIds);

        return $collision === null
            ? ConfigurationException::appUnknown($overrideId, $knownIds)
            : ConfigurationException::appNameMismatch($overrideId, $collision);
    }

    private function findCollidingId(AppId $overrideId, AppIdCollection $knownIds): ?AppId
    {
        return $knownIds->findFirstWhere(static fn(AppId $known): bool => $known->collidesWith($overrideId));
    }
}
