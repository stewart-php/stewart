<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Manifest;

use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use Stewart\Contracts\Generated\Manifest;
use Stewart\PHPStan\Cache\ManifestResultCacheValue;

// PHPStan runs with the project's autoloader, so the generated Manifest is read as plain PHP, once per analysis.
final class ManifestCatalogLoader
{
    private ?ManifestCatalog $catalog = null;

    private ?string $olderMajorNamespace = null;

    private bool $loaded = false;

    public function __construct(
        private readonly ?string $manifestClass,
        private readonly ReflectionProvider $reflection,
        private readonly GeneratedManifestLocator $locator,
    ) {}

    // Tracked also while there is no Manifest, so the first make generate brings the findings.
    /** @param Scope&DependencyTracker $scope */
    public function findCatalogTrackedBy(Scope $scope): ?ManifestCatalog
    {
        $scope->trackValueDependency(ManifestResultCacheValue::class, ManifestResultCacheValue::MANIFEST_KEY);

        return $this->findCatalog();
    }

    public function findCatalog(): ?ManifestCatalog
    {
        $this->loadOnce();

        return $this->catalog;
    }

    // Tracked only while it reports, so regenerating a current tree does not invalidate every file.
    /** @param Scope&DependencyTracker $scope */
    public function findOlderMajorNamespaceTrackedBy(Scope $scope): ?string
    {
        $namespace = $this->findOlderMajorNamespace();

        if ($namespace !== null) {
            $scope->trackValueDependency(ManifestResultCacheValue::class, ManifestResultCacheValue::MANIFEST_KEY);
        }

        return $namespace;
    }

    public function findOlderMajorNamespace(): ?string
    {
        $this->loadOnce();

        return $this->olderMajorNamespace;
    }

    private function loadOnce(): void
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $this->catalog = $this->loadCatalog();
        }
    }

    private function loadCatalog(): ?ManifestCatalog
    {
        $class = $this->manifestClass ?? $this->locator->findManifestClass();

        if ($class === null) {
            return null;
        }

        $separator = strrpos($class, '\\');
        $namespace = $separator === false ? '' : substr($class, 0, $separator);

        // Static reflection first: an older tree's classes fail to load against today's contracts, and PHP cannot
        // catch that.
        if (!$this->reflection->hasClass($namespace . '\\Actions')) {
            $this->olderMajorNamespace = $this->reflection->hasClass($namespace . '\\Services') ? $namespace : null;

            return null;
        }

        if (!class_exists($class) || !is_a($class, Manifest::class, true)) {
            return null;
        }

        return ManifestCatalog::fromLists($class::listEntityIds(), $class::listIgnoredEntityIds(), $class::listActionNames());
    }
}
