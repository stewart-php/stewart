<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Wire\ListOf;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Model\ResourceScope;

final readonly class AppActivityReport
{
    /**
     * @param list<SubscriptionActivityReport> $subscriptionReports
     * @param list<ScheduleActivityReport> $scheduleReports
     */
    public function __construct(
        public ResourceScope $scope,
        public AppState $state,
        public int $subscriptions,
        public int $schedules,
        public int $delivered,
        public int $subscriptionDropped,
        public int $scheduleRuns,
        public int $publishes,
        public int $failures,
        public int $suppressed,
        public int $handled = 0,
        public ?Instant $lastHandledAt = null,
        public ?Instant $nextRunAt = null,
        #[ListOf(SubscriptionActivityReport::class)]
        public array $subscriptionReports = [],
        #[ListOf(ScheduleActivityReport::class)]
        public array $scheduleReports = [],
    ) {}
}
