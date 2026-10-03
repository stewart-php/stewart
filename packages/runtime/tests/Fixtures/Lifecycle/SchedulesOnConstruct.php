<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Lifecycle;

use Stewart\Contracts\App;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Time\Duration;

final class SchedulesOnConstruct implements App
{
    public static int $runs = 0;

    public static function reset(): void
    {
        self::$runs = 0;
    }

    public function __construct(Scheduler $scheduler)
    {
        $scheduler->runEvery(Duration::seconds(10), static function (): void {
            ++self::$runs;
        });
    }

    public function initialize(): void {}

    public function dispose(): void {}
}
