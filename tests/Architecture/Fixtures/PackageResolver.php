<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture\Fixtures;

use Composer\Autoload\ClassLoader;
use LogicException;
use Stewart\Tests\Architecture\Fixtures\Collection\PackageManifestCollection;

final readonly class PackageResolver
{
    private function __construct(
        private ClassLoader $loader,
        private string $vendorDir,
        private PackageManifestCollection $packages,
    ) {}

    public static function createForRepository(string $root, PackageManifestCollection $packages): self
    {
        $vendorDir = $root . '/vendor';
        $loader = ClassLoader::getRegisteredLoaders()[$vendorDir]
            ?? throw new LogicException(\sprintf('No Composer loader is registered for %s.', $vendorDir));

        return new self($loader, $vendorDir, $packages);
    }

    public function findOwnerOf(string $className): ImportOwner
    {
        if (!str_contains($className, '\\')) {
            return ImportOwner::createForCore();
        }

        $file = $this->loader->findFile($className);

        if ($file === false) {
            return ImportOwner::createForCore();
        }

        // The optimized class map builds paths through vendor/composer/.., so resolve before matching.
        $real = realpath($file);

        if ($real === false) {
            throw new LogicException(\sprintf('%s maps to %s, which does not exist.', $className, $file));
        }

        $package = $this->packages->findOwnerOfFile($real);

        if ($package !== null) {
            return ImportOwner::createForPackage($package->name);
        }

        // Composer's own runtime classes live in vendor/composer; a package requires them as composer-runtime-api.
        if (str_starts_with($real, $this->vendorDir . '/composer/')) {
            return ImportOwner::createForPackage('composer-runtime-api');
        }

        $vendored = '#^' . preg_quote($this->vendorDir . '/', '#') . '(?<package>[^/]+/[^/]+)/#';

        if (preg_match($vendored, $real, $match) === 1) {
            return ImportOwner::createForPackage($match['package']);
        }

        return ImportOwner::createForOutsidePath($real);
    }
}
