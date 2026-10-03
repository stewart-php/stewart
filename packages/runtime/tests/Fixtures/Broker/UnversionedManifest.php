<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Stewart\Contracts\Generated\Manifest;
use Stewart\Runtime\Tests\Fixtures\Generated\Code\Manifest as GeneratedManifest;

final class UnversionedManifest implements Manifest
{
    private function __construct() {}

    public static function listEntityIds(): array
    {
        return GeneratedManifest::listEntityIds();
    }

    public static function listIgnoredEntityIds(): array
    {
        return GeneratedManifest::listIgnoredEntityIds();
    }
}
