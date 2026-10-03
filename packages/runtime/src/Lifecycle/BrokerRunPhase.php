<?php

declare(strict_types=1);

namespace Stewart\Runtime\Lifecycle;

enum BrokerRunPhase
{
    case Idle;
    case Running;
    case Stopping;
    case Stopped;

    public function canEnter(self $next): bool
    {
        return match ($this) {
            self::Idle, self::Stopped => $next === self::Running,
            self::Running => $next === self::Stopping,
            self::Stopping => $next === self::Stopped,
        };
    }
}
