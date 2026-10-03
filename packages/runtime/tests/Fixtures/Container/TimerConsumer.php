<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\Time\Timers;

final readonly class TimerConsumer
{
    public function __construct(public Timers $timers) {}
}
