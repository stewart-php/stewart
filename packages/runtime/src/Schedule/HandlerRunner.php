<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Closure;

interface HandlerRunner
{
    /** @param Closure(): void $work */
    public function run(Closure $work): void;
}
