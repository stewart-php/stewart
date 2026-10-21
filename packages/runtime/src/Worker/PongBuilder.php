<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Dispatch\SubscriptionActivity;
use Stewart\Runtime\Health\ProcessMemory;
use Stewart\Runtime\Ipc\Message\AppActivityReport;
use Stewart\Runtime\Ipc\Message\Ping;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Ipc\Message\ScheduleActivityReport;
use Stewart\Runtime\Ipc\Message\SubscriptionActivityReport;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Schedule\ScheduleEntry;
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
        private ProcessMemory $processMemory,
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
            memoryBytes: $this->processMemory->measureResidentBytes(),
            apps: $reports,
            store: $this->store?->getHealth(),
        );
    }

    private function buildActivityReport(ResourceScope $scope, AppState $state): AppActivityReport
    {
        $activity = $this->activityCounters->findOrCreateActivityForScope($scope);
        $subscriptionReports = array_map($this->buildSubscriptionReport(...), $this->dispatcher->listActivitiesOf($scope));
        $scheduleReports = array_map($this->buildScheduleReport(...), $this->schedules->listEntriesOf($scope));

        return new AppActivityReport(
            scope: $scope,
            state: $state,
            subscriptions: \count($subscriptionReports),
            schedules: \count($scheduleReports),
            delivered: $activity->delivered,
            subscriptionDropped: $activity->dropped,
            scheduleRuns: $activity->scheduleRuns,
            publishes: $activity->publishes,
            failures: $activity->failures,
            suppressed: $activity->suppressed,
            handled: $activity->handled,
            lastHandledAt: $activity->lastHandledAt,
            nextRunAt: $this->findEarliestRun($scheduleReports),
            subscriptionReports: $subscriptionReports,
            scheduleReports: $scheduleReports,
        );
    }

    private function buildSubscriptionReport(SubscriptionActivity $activity): SubscriptionActivityReport
    {
        $subscription = $activity->subscription;

        return new SubscriptionActivityReport(
            subscriptionId: $subscription->id,
            kind: $subscription->kind,
            match: $subscription->describeMatch(),
            exact: $subscription->entityFilter === null && $subscription->selector->findExactPattern() !== null,
            received: $activity->received,
            handled: $activity->handled,
            dropped: $activity->dropped,
            lastReceivedAt: $activity->lastReceivedAt,
            lastHandledAt: $activity->lastHandledAt,
        );
    }

    private function buildScheduleReport(ScheduleEntry $entry): ScheduleActivityReport
    {
        return new ScheduleActivityReport(
            taskId: $entry->origin->taskId,
            description: $entry->origin->description,
            nextRunAt: $entry->getNextRunAt(),
            lastRunAt: $entry->lastRunAt,
        );
    }

    /** @param list<ScheduleActivityReport> $scheduleReports */
    private function findEarliestRun(array $scheduleReports): ?Instant
    {
        $earliest = null;

        foreach ($scheduleReports as $report) {
            if ($report->nextRunAt !== null && ($earliest === null || $earliest->isAfter($report->nextRunAt))) {
                $earliest = $report->nextRunAt;
            }
        }

        return $earliest;
    }
}
