<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Http;

use Throwable;

interface ProbeListener
{
    /** @throws Throwable */
    public function start(): void;

    public function stop(): void;
}
