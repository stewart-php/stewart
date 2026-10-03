<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Closure;

use function Amp\async;

final readonly class AsyncHandlerRunner implements HandlerRunner
{
    public function run(Closure $work): void
    {
        async($work)->ignore();
    }
}
