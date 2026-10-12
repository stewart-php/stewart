<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\Exception\ExceptionReason;

enum WorkerError: string implements ExceptionReason
{
    case Unknown = 'worker_unknown';
    case ShuttingDown = 'shutting_down';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::Unknown => 'No worker "{workerId}"; `stewart status` lists the workers.',
            self::ShuttingDown => 'Stewart is shutting down, so worker {workerId} is not restarted.',
        };
    }
}
