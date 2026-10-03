<?php

declare(strict_types=1);

namespace Stewart\Testing\Time;

use Closure;
use Stewart\Contracts\Time\MonotonicTime;
use Stewart\Contracts\Time\TimerHandle;

final class ManualTimer implements TimerHandle
{
    private bool $pending = true;

    /**
     * @param Closure(): void $callback
     * @param Closure(self): void $onRelease
     */
    public function __construct(
        public readonly MonotonicTime $dueAt,
        private readonly Closure $callback,
        private readonly Closure $onRelease,
    ) {}

    public function cancel(): void
    {
        $this->release();
    }

    public function isPending(): bool
    {
        return $this->pending;
    }

    public function fire(): void
    {
        if (!$this->pending) {
            return;
        }

        $this->release();
        ($this->callback)();
    }

    private function release(): void
    {
        if (!$this->pending) {
            return;
        }

        $this->pending = false;
        ($this->onRelease)($this);
    }
}
