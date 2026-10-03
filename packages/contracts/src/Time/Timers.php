<?php

declare(strict_types=1);

namespace Stewart\Contracts\Time;

use Closure;

interface Timers
{
    /** @param Closure(): void $callback */
    public function startTimer(Duration $delay, Closure $callback): TimerHandle;
}
