<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Assembler;

use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\HaSession;
use Stewart\Runtime\Control\Protocol\Status\DaemonInfo;

final readonly class DaemonInfoBuilder
{
    public function __construct(
        private HaSession $session,
        private string $daemonVersion,
        private DaemonStartTime $startTime,
    ) {}

    public function buildDaemonInfo(): DaemonInfo
    {
        return new DaemonInfo(
            pid: getmypid() ?: 0,
            startedAt: $this->startTime->getStartedAt(),
            memoryBytes: memory_get_usage(true),
            version: $this->daemonVersion,
            timeZone: $this->session->getTimeZone()->getName(),
            entities: $this->session->countEntities(),
            haVersion: $this->session->getHaVersion(),
        );
    }
}
