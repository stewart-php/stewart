<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Timers;

final readonly class NeedsClock implements App
{
    public function __construct(
        public Clock $clock,
        public Timers $timers,
    ) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
