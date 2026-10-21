<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Lifecycle;

use Stewart\Contracts\App;

final class LegacyDisposer implements App
{
    public static int $disposals = 0;

    public static function reset(): void
    {
        self::$disposals = 0;
    }

    public function initialize(): void {}

    public function dispose(): void
    {
        ++self::$disposals;
    }
}
