<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Wire\ListOf;
use Stewart\Runtime\Lifecycle\AppState;

final readonly class AppStatus
{
    /** @param list<ServiceCallStats> $serviceCalls */
    public function __construct(
        public string $id,
        public string $class,
        public ?int $workerId,
        public ?AppState $state,
        public ?AppPauseStatus $pause,
        public ?AppPauseStatus $configPauseOverride,
        public ?Instant $reportedAt,
        public int $subscriptions,
        public int $schedules,
        public int $exposedEntities,
        public AppCounters $counters,
        #[ListOf(ServiceCallStats::class)]
        public array $serviceCalls,
        public ?FailureReport $lastFailure,
    ) {}
}
