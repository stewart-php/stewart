<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Runtime\Exception\ControlException;
use Throwable;

interface ControlPlane
{
    /** @throws ControlException|Throwable */
    public function start(): void;

    public function stop(): void;
}
