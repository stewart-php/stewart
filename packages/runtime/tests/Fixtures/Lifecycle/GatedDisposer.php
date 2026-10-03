<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Lifecycle;

use Stewart\Contracts\App;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\State\StateChange;
use Stewart\Testing\Async\Latch;

final class GatedDisposer implements App
{
    public static ?Latch $latch = null;

    /** @var list<string> */
    public static array $log = [];

    public function __construct(private readonly HaContext $ha) {}

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
        $this->ha->watchStateChanges('light.hall')->subscribe(static function (StateChange $change): void {
            self::$log[] = 'handled ' . $change->entityId;
        });
    }

    public function dispose(): void
    {
        self::$log[] = 'dispose start';

        self::$latch?->waitUntilOpen();

        self::$log[] = 'dispose end';
    }
}
