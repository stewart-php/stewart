<?php

declare(strict_types=1);

namespace Stewart\Runtime\Health;

enum ProbeStatus: string
{
    case Alive = 'alive';
    case Ready = 'ready';
    case NotReady = 'not_ready';

    public function isHealthy(): bool
    {
        return $this !== self::NotReady;
    }
}
