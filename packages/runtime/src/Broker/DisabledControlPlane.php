<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

final class DisabledControlPlane implements ControlPlane
{
    public function start(): void {}

    public function stop(): void {}
}
