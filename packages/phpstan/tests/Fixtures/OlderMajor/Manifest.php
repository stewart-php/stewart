<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Tests\Fixtures\OlderMajor;

use Stewart\Contracts\Generated\Manifest as ManifestContract;

// Written by Stewart 1.7, before Manifest listed action names: loading it is a fatal error the tests must never reach.
final class Manifest implements ManifestContract
{
    public static function listEntityIds(): array
    {
        return ['light.hall'];
    }

    public static function listIgnoredEntityIds(): array
    {
        return [];
    }
}
