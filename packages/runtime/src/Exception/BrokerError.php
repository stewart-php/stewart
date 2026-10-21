<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\Exception\ExceptionReason;

enum BrokerError: string implements ExceptionReason
{
    case NoWorkersStarted = 'no_workers_started';
    case WorkerSpawnTimedOut = 'worker_spawn_timed_out';
    case ReloadConfigChanged = 'reload_config_changed';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::NoWorkersStarted => 'None of {workers} workers could be started.',
            self::WorkerSpawnTimedOut => 'Worker {worker} did not connect within {timeout}.',
            self::ReloadConfigChanged => '{path} changed while the reload checked it, so nothing was applied; reload again.',
        };
    }
}
