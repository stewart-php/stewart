<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Http;

final readonly class DisabledProbeListener implements ProbeListener
{
    public function start(): void {}

    public function stop(): void {}
}
