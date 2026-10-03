<?php

declare(strict_types=1);

namespace Stewart\Runtime\Lifecycle;

enum WorkerPhase: string
{
    case Live = 'live';
    case Spawning = 'spawning';
    case RestartScheduled = 'restart_scheduled';
    case Quarantined = 'quarantined';
    case Stopped = 'stopped';

    public function canEnter(self $next): bool
    {
        return match ($this) {
            self::Stopped => \in_array($next, [self::Spawning, self::RestartScheduled, self::Quarantined], true),
            self::Spawning => $next === self::Live || $next === self::Stopped,
            self::Live => $next === self::Stopped,
            self::RestartScheduled, self::Quarantined => $next === self::Spawning || $next === self::Stopped,
        };
    }
}
