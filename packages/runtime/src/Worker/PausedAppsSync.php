<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\Scope\ScopeLifecycle;

final readonly class PausedAppsSync
{
    public function __construct(
        private AppLifecycle $apps,
        private AppResources $resources,
        private ScopeLifecycle $scopes,
        private WorkerLogger $logger,
    ) {}

    public function applyPausedAppIds(AppIdCollection $pausedAppIds): void
    {
        foreach ($this->apps->listSlots() as $slot) {
            $scope = $slot->scope();

            if ($this->scopes->isClosed($scope)) {
                continue;
            }

            $shouldPause = $pausedAppIds->containsId($slot->id());

            if ($shouldPause === $this->scopes->isPaused($scope)) {
                continue;
            }

            if ($shouldPause) {
                $this->resources->pauseScope($scope);
                $this->logger->forScope($scope)->info('App paused');
            } else {
                $this->resources->resumeScope($scope);
                $this->logger->forScope($scope)->info('App resumed');
            }
        }
    }
}
