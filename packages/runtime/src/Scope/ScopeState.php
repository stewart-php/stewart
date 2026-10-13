<?php

declare(strict_types=1);

namespace Stewart\Runtime\Scope;

use LogicException;
use Stewart\Runtime\Model\ResourceScope;

// Shared by every queue and schedule of one scope, so none of them keeps its own copy of these flags.
final class ScopeState
{
    private bool $paused = false;

    public function __construct(
        public readonly ResourceScope $scope,
        private ScopePhase $phase = ScopePhase::Starting,
    ) {}

    public function activate(): void
    {
        if ($this->phase === ScopePhase::Closed) {
            throw new LogicException(\sprintf('Scope "%s" was released and cannot be activated again.', $this->scope));
        }

        $this->phase = ScopePhase::Live;
    }

    public function pause(): void
    {
        if ($this->scope->isShared()) {
            throw new LogicException('The shared scope cannot be paused.');
        }

        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused = false;
    }

    public function close(): void
    {
        $this->phase = ScopePhase::Closed;
    }

    public function isLive(): bool
    {
        return $this->phase === ScopePhase::Live;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function isClosed(): bool
    {
        return $this->phase === ScopePhase::Closed;
    }
}
