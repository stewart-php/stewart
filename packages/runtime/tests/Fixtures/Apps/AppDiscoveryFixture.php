<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Apps;

use Stewart\Runtime\App\AppDiscovery;
use Stewart\Runtime\Config\Psr4Map;

final class AppDiscoveryFixture
{
    public static function createForFixtureDirectory(string $directory = 'Apps'): AppDiscovery
    {
        $tests = \dirname(__DIR__, 2);

        return new AppDiscovery(new Psr4Map(['Stewart\\Runtime\\Tests\\' => [$tests]]), $tests . '/Fixtures/' . $directory);
    }
}
