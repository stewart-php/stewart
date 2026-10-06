<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker\Http;

use Stewart\Runtime\Broker\Http\ProbeListener;

final class RecordingProbeListener implements ProbeListener
{
    public bool $started = false;

    public bool $stopped = false;

    public function start(): void
    {
        $this->started = true;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}
