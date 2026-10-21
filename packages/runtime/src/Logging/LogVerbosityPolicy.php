<?php

declare(strict_types=1);

namespace Stewart\Runtime\Logging;

use Stewart\Runtime\Model\AppVerbosity;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\ResourceScope;

final class LogVerbosityPolicy
{
    /** @param array<string, AppVerbosity> $appVerbosities */
    public function __construct(
        private readonly LogLevel $defaultLevel,
        private array $appVerbosities = [],
    ) {}

    public function admitsLevel(LogLevel $level, ?ResourceScope $scope): bool
    {
        return !$level->isBelow($this->findLevelFor($scope));
    }

    public function isTracing(ResourceScope $scope): bool
    {
        return $scope->appId !== null && ($this->appVerbosities[$scope->appId->value]->trace ?? false);
    }

    /** @param array<string, AppVerbosity> $appVerbosities */
    public function replaceAppVerbosities(array $appVerbosities): void
    {
        $this->appVerbosities = $appVerbosities;
    }

    private function findLevelFor(?ResourceScope $scope): LogLevel
    {
        $appId = $scope?->appId;

        return $appId === null ? $this->defaultLevel : ($this->appVerbosities[$appId->value]->logLevel ?? $this->defaultLevel);
    }
}
