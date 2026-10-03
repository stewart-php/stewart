<?php

declare(strict_types=1);

namespace Stewart\Runtime\Lifecycle;

enum WorkerHandleState
{
    case Starting;
    case Ready;
    case Terminated;

    public function canEnter(self $next): bool
    {
        return match ($this) {
            self::Starting => $next === self::Ready || $next === self::Terminated,
            self::Ready => $next === self::Terminated,
            self::Terminated => false,
        };
    }
}
