<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Schedule\ScheduleRegistry;
use Stewart\Runtime\Scope\ScopeLifecycle;

final readonly class AppResources
{
    public function __construct(
        private ScopeLifecycle $scopes,
        private LocalDispatcher $dispatcher,
        private ScheduleRegistry $schedules,
    ) {}

    public function activateScope(ResourceScope $scope): void
    {
        $this->scopes->activateScope($scope);

        if (!$this->scopes->isLive($scope)) {
            return;
        }

        $this->dispatcher->activateQueuesOf($scope);
        $this->schedules->startEntriesOf($scope);
    }

    public function releaseScope(ResourceScope $scope): void
    {
        $this->scopes->releaseScope($scope);
        $this->dispatcher->cancelSubscriptionsOf($scope);
        $this->schedules->cancelEntriesOf($scope);
    }

    public function releaseAll(): void
    {
        $this->scopes->stopAll();
        $this->dispatcher->cancelAll();
        $this->schedules->cancelAll();
    }
}
