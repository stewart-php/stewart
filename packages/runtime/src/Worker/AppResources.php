<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Schedule\EntityTimeScheduler;
use Stewart\Runtime\Schedule\ScheduleRegistry;
use Stewart\Runtime\Scope\ScopeLifecycle;
use Stewart\Runtime\Worker\Exposure\ExposedHandleRegistry;

final readonly class AppResources
{
    public function __construct(
        private ScopeLifecycle $scopes,
        private LocalDispatcher $dispatcher,
        private ScheduleRegistry $schedules,
        private EntityTimeScheduler $entityTimes,
        private ExposedHandleRegistry $exposedHandles,
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

    public function pauseScope(ResourceScope $scope): void
    {
        if ($this->scopes->isClosed($scope)) {
            return;
        }

        $this->scopes->pauseScope($scope);
        $this->dispatcher->pauseQueuesOf($scope);
        $this->schedules->pauseEntriesOf($scope);
    }

    public function resumeScope(ResourceScope $scope): void
    {
        if ($this->scopes->isClosed($scope)) {
            return;
        }

        $this->scopes->resumeScope($scope);
        $this->dispatcher->resumeQueuesOf($scope);
        $this->schedules->resumeEntriesOf($scope);
        $this->entityTimes->refreshTasksOf($scope);
    }

    public function releaseScope(ResourceScope $scope): void
    {
        $this->scopes->releaseScope($scope);
        $this->entityTimes->cancelTasksOf($scope);
        $this->dispatcher->cancelSubscriptionsOf($scope);
        $this->schedules->cancelEntriesOf($scope);
        $this->exposedHandles->releaseHandlesOf($scope);
    }

    public function releaseAll(): void
    {
        $this->scopes->stopAll();
        $this->entityTimes->cancelAll();
        $this->dispatcher->cancelAll();
        $this->schedules->cancelAll();
        $this->exposedHandles->releaseAll();
    }
}
