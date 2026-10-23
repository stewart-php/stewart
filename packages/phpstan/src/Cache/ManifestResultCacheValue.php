<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Cache;

use PHPStan\Analyser\ResultCache\ResultCacheValueExtension;
use Stewart\PHPStan\Manifest\ManifestCatalogLoader;

// The findings depend on the generated Manifest, which the files that use entity IDs do not import.
final readonly class ManifestResultCacheValue implements ResultCacheValueExtension
{
    public const string MANIFEST_KEY = 'manifest';

    private const string WITHOUT_MANIFEST = 'none';

    public function __construct(private ManifestCatalogLoader $manifests) {}

    public function getValue(string $key): string
    {
        return $this->manifests->findCatalog()?->computeHash() ?? self::WITHOUT_MANIFEST;
    }

    public function keyToResultCache(string $key): string
    {
        return $key;
    }

    public function keyFromResultCache(string $storedKey): string
    {
        return $storedKey;
    }
}
