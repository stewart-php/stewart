<?php

declare(strict_types=1);

namespace Stewart\Runtime\Scope;

use LogicException;
use Stewart\Runtime\Model\ResourceScope;

final class ScopeLifecycle
{
    /** @var array<string, true> */
    private array $liveScopes = [];

    /** @var array<string, true> */
    private array $releasedScopes = [];

    private bool $stopped = false;

    public function activateScope(ResourceScope $scope): void
    {
        if (isset($this->releasedScopes[$scope->wireValue()])) {
            throw new LogicException(\sprintf('Scope "%s" was released and cannot be activated again.', $scope));
        }

        if ($this->stopped) {
            return;
        }

        $this->liveScopes[$scope->wireValue()] = true;
    }

    public function releaseScope(ResourceScope $scope): void
    {
        $this->releasedScopes[$scope->wireValue()] = true;
        unset($this->liveScopes[$scope->wireValue()]);
    }

    public function stopAll(): void
    {
        $this->stopped = true;
        $this->liveScopes = [];
    }

    public function isLive(ResourceScope $scope): bool
    {
        return isset($this->liveScopes[$scope->wireValue()]);
    }

    public function isClosed(ResourceScope $scope): bool
    {
        return $this->stopped || isset($this->releasedScopes[$scope->wireValue()]);
    }
}
