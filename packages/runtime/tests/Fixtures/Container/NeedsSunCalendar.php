<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;
use Stewart\Contracts\Sun\SunCalendar;

final readonly class NeedsSunCalendar implements App
{
    public function __construct(public SunCalendar $sunCalendar) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
