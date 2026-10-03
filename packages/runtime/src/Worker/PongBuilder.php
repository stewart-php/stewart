<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Ipc\Message\AppActivityReport;
use Stewart\Runtime\Ipc\Message\Ping;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Schedule\ScheduleRegistry;
use Stewart\Store\GuardedStoreBackend;

final readonly class PongBuilder
{
    public function __construct(
        private AppLifecycle $apps,
        private LocalDispatcher $dispatcher,
        private ScheduleRegistry $schedules,
        private AppActivityCounters $activityCounters,
        private Clock $clock,
        private ?GuardedStoreBackend $store = null,
    ) {}

    public function buildPongFor(Ping $ping): Pong
    {
        $reports = [];

        foreach ($this->apps->listSlots() as $slot) {
            $reports[] = $this->buildActivityReport($slot->scope(), $slot->state);
        }

        $shared = $this->buildActivityReport(ResourceScope::shared(), AppState::Running);

        if ($shared->subscriptions > 0 || $shared->schedules > 0 || !$this->activityCounters->findOrCreateActivityForScope(ResourceScope::shared())->isIdle()) {
            $reports[] = $shared;
        }

        return new Pong(
            nonce: $ping->nonce,
            loopLag: $this->clock->getNow()->elapsedSince($ping->sentAt),
            memoryBytes: memory_get_usage(true),
            apps: $reports,
            store: $this->store?->getHealth(),
        );
    }

    private function buildActivityReport(ResourceScope $scope, AppState $state): AppActivityReport
    {
        $activity = $this->activityCounters->findOrCreateActivityForScope($scope);

        return new AppActivityReport(
            scope: $scope,
            state: $state,
            subscriptions: $this->dispatcher->countFor($scope),
            schedules: $this->schedules->countFor($scope),
            delivered: $activity->delivered,
            subscriptionDropped: $activity->dropped,
            scheduleRuns: $activity->scheduleRuns,
            publishes: $activity->publishes,
            failures: $activity->failures,
        );
    }
}
