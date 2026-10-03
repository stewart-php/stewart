<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture\Fixtures\Collection;

use LogicException;
use Stewart\Contracts\Collection\ListCollection;
use Stewart\Tests\Architecture\Fixtures\PackageManifest;

/** @extends ListCollection<PackageManifest> */
final readonly class PackageManifestCollection extends ListCollection
{
    /** @param iterable<PackageManifest> $manifests */
    public static function fromManifests(iterable $manifests): self
    {
        return self::fromList($manifests);
    }

    public static function discoverUnder(string $packagesRoot): self
    {
        $manifestFiles = glob($packagesRoot . '/*/composer.json');

        if ($manifestFiles === false || $manifestFiles === []) {
            throw new LogicException(\sprintf('No package manifests under %s.', $packagesRoot));
        }

        sort($manifestFiles);

        return self::fromManifests(array_map(static fn(string $file): PackageManifest => PackageManifest::readFromDirectory(\dirname($file)), $manifestFiles));
    }

    public function findOwnerOfFile(string $realFile): ?PackageManifest
    {
        return $this->findFirstWhere(static fn(PackageManifest $manifest): bool => $manifest->ownsFile($realFile));
    }
}
