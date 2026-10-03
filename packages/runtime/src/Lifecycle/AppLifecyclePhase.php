<?php

declare(strict_types=1);

namespace Stewart\Runtime\Lifecycle;

enum AppLifecyclePhase
{
    case Starting;
    case Stopping;
    case Closed;

    public function canEnter(self $next): bool
    {
        return match ($this) {
            self::Starting => $next === self::Stopping,
            self::Stopping => $next === self::Closed,
            self::Closed => false,
        };
    }
}
