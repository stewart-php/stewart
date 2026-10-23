<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Manifest;

use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use Stewart\Contracts\Generated\ActionManifest;
use Stewart\Contracts\Generated\Manifest;
use Stewart\PHPStan\Cache\ManifestResultCacheValue;

// PHPStan runs with the project's autoloader, so the generated Manifest is read as plain PHP, once per analysis.
final class ManifestCatalogLoader
{
    private ?ManifestCatalog $catalog = null;

    private bool $loaded = false;

    public function __construct(private readonly string $manifestClass) {}

    // Tracked also while there is no Manifest, so the first make generate brings the findings.
    /** @param Scope&DependencyTracker $scope */
    public function findCatalogTrackedBy(Scope $scope): ?ManifestCatalog
    {
        $scope->trackValueDependency(ManifestResultCacheValue::class, ManifestResultCacheValue::MANIFEST_KEY);

        return $this->findCatalog();
    }

    public function findCatalog(): ?ManifestCatalog
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $this->catalog = $this->loadCatalog();
        }

        return $this->catalog;
    }

    private function loadCatalog(): ?ManifestCatalog
    {
        $class = $this->manifestClass;

        if (!class_exists($class) || !is_a($class, Manifest::class, true)) {
            return null;
        }

        return ManifestCatalog::fromLists(
            $class::listEntityIds(),
            $class::listIgnoredEntityIds(),
            is_a($class, ActionManifest::class, true) ? $class::listActionNames() : null,
        );
    }
}
