<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Stewart\Runtime\Broker\ControlPlane;

final class RecordingControlPlane implements ControlPlane
{
    public bool $started = false;

    public function start(): void
    {
        $this->started = true;
    }

    public function stop(): void {}
}
