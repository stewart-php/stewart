<?php

declare(strict_types=1);

namespace Stewart\Runtime\Scope;

use Stewart\Runtime\Model\ResourceScope;

final class ScopeLifecycle
{
    /** @var array<string, ScopeState> */
    private array $statesByScope = [];

    private bool $stopped = false;

    public function findOrCreateStateOf(ResourceScope $scope): ScopeState
    {
        return $this->statesByScope[$scope->wireValue()] ??= new ScopeState($scope, $this->stopped ? ScopePhase::Closed : ScopePhase::Starting);
    }

    public function activateScope(ResourceScope $scope): void
    {
        if ($this->stopped) {
            return;
        }

        $this->findOrCreateStateOf($scope)->activate();
    }

    public function releaseScope(ResourceScope $scope): void
    {
        $this->findOrCreateStateOf($scope)->close();
    }

    public function pauseScope(ResourceScope $scope): void
    {
        $this->findOrCreateStateOf($scope)->pause();
    }

    public function resumeScope(ResourceScope $scope): void
    {
        $this->findOrCreateStateOf($scope)->resume();
    }

    public function stopAll(): void
    {
        $this->stopped = true;

        foreach ($this->statesByScope as $state) {
            $state->close();
        }
    }
}
