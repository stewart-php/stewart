<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Lifecycle;

use Stewart\Contracts\App;
use Stewart\Testing\Async\Latch;

final class GatedInitializer implements App
{
    public static ?Latch $latch = null;

    /** @var list<string> */
    public static array $log = [];

    public static function reset(): void
    {
        self::$latch = new Latch();
        self::$log = [];
    }

    public static function open(): void
    {
        self::$latch?->open();
    }

    public function initialize(): void
    {
        self::$log[] = 'initialize start';

        self::$latch?->waitUntilOpen();

        self::$log[] = 'initialize end';
    }

    public function dispose(): void
    {
        self::$log[] = 'dispose';
    }
}
