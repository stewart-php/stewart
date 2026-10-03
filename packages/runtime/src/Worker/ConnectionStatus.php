<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

final class ConnectionStatus
{
    public private(set) bool $connected = true;

    public function markLost(): bool
    {
        $changed = $this->connected;
        $this->connected = false;

        return $changed;
    }

    public function markRestored(): bool
    {
        $changed = !$this->connected;
        $this->connected = true;

        return $changed;
    }
}
