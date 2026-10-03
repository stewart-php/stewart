<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Worker;

use Closure;
use Stewart\Runtime\Schedule\HandlerRunner;

final readonly class InlineHandlerRunner implements HandlerRunner
{
    public function run(Closure $work): void
    {
        $work();
    }
}
