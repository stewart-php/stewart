<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use LogicException;
use Stewart\Contracts\App;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Model\ResourceScope;

final class AppSlot
{
    private ?App $app = null;

    public private(set) AppState $state = AppState::Declared;

    private bool $disposalClaimed = false;

    public function __construct(public readonly WorkerApp $definition) {}

    public function id(): AppId
    {
        return $this->definition->id;
    }

    public function scope(): ResourceScope
    {
        return ResourceScope::forApp($this->definition->id);
    }

    public function isInState(AppState $state): bool
    {
        return $this->state === $state;
    }

    public function app(): App
    {
        return $this->app ?? throw new LogicException(\sprintf('App "%s" was never constructed.', $this->id()));
    }

    public function moveTo(AppState $state): void
    {
        if (!$this->state->canEnter($state)) {
            throw new LogicException(\sprintf('App "%s" cannot move from %s to %s.', $this->id(), $this->state->value, $state->value));
        }

        $this->state = $state;
    }

    public function markConstructed(App $app): void
    {
        $this->moveTo(AppState::Constructed);
        $this->app = $app;
    }

    public function claimDisposal(): bool
    {
        if ($this->disposalClaimed || $this->app === null) {
            return false;
        }

        $this->disposalClaimed = true;

        if ($this->needsDisposal()) {
            $this->moveTo(AppState::Disposing);
        }

        return true;
    }

    public function markDisposed(): void
    {
        if ($this->state === AppState::Disposing) {
            $this->moveTo(AppState::Disposed);
        }
    }

    public function needsDisposal(): bool
    {
        return $this->state === AppState::Running || $this->state === AppState::Initialized;
    }
}
