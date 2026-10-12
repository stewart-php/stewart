<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Runtime\Model\WorkerId;

enum WorkerRestartOutcome
{
    case Begun;
    case AlreadyUnderway;

    public function describeForWorker(WorkerId $workerId): string
    {
        return match ($this) {
            self::Begun => \sprintf('Worker %d is restarting.', $workerId->value),
            self::AlreadyUnderway => \sprintf('Worker %d is already starting or stopping.', $workerId->value),
        };
    }
}
