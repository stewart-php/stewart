<?php

declare(strict_types=1);

namespace Stewart\Support\Time;

use Revolt\EventLoop;
use Stewart\Contracts\Time\TimerHandle;

/** @internal */
final class RevoltTimerHandle implements TimerHandle
{
    private ?string $identifier = null;

    private bool $pending = true;

    public function attachWatcher(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function markFired(): void
    {
        $this->pending = false;
        $this->identifier = null;
    }

    public function cancel(): void
    {
        $this->pending = false;

        if ($this->identifier === null) {
            return;
        }

        EventLoop::cancel($this->identifier);
        $this->identifier = null;
    }

    public function isPending(): bool
    {
        return $this->pending;
    }
}
