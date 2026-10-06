<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Model\ResourceScope;

final readonly class AppActivityReport
{
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
    ) {}
}
