<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use LogicException;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Instant;

final class DaemonStartTime
{
    private ?Instant $startedAt = null;

    public function __construct(private readonly Clock $clock) {}

    public function recordStart(): void
    {
        $this->startedAt = $this->clock->getNow();
    }

    public function getStartedAt(): Instant
    {
        return $this->startedAt ?? throw new LogicException('The daemon start time is read before the run started.');
    }
}
