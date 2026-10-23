<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Manifest;

use Composer\Autoload\ClassLoader;
use PHPStan\Reflection\ReflectionProvider;

final readonly class GeneratedManifestLocator
{
    private const string MANIFEST = 'Manifest';

    private const array ROOTS = ['Actions', 'Services'];

    public function __construct(private ReflectionProvider $reflection) {}

    // The project's Composer loader is the one that loaded this extension; a PHAR brings a loader of its own.
    public function findManifestClass(): ?string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $vendorDirectory => $loader) {
            if (!str_starts_with($vendorDirectory, 'phar://') && $loader->findFile(self::class) !== false) {
                return $this->findManifestClassAmong(array_keys($loader->getPrefixesPsr4()));
            }
        }

        return null;
    }

    // Probed through static reflection only: a tree from an older major must not be loaded.
    /** @param list<string> $namespacePrefixes */
    public function findManifestClassAmong(array $namespacePrefixes): ?string
    {
        $found = array_values(array_filter($namespacePrefixes, $this->holdsGeneratedClasses(...)));

        return \count($found) === 1 ? $found[0] . self::MANIFEST : null;
    }

    private function holdsGeneratedClasses(string $namespacePrefix): bool
    {
        return $this->reflection->hasClass($namespacePrefix . self::MANIFEST)
            && array_any(self::ROOTS, fn(string $root): bool => $this->reflection->hasClass($namespacePrefix . $root));
    }
}
