<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Wire\ListOf;
use Stewart\Store\StoreHealth;

final readonly class RuntimeSnapshot
{
    /**
     * @param list<WorkerStatus> $workers
     * @param list<AppStatus> $apps
     * @param list<RegistrationInfo> $subscriptions
     */
    public function __construct(
        public Instant $takenAt,
        public DaemonInfo $daemon,
        public ConnectionState $connection,
        public BrokerStats $broker,
        #[ListOf(WorkerStatus::class)]
        public array $workers,
        #[ListOf(AppStatus::class)]
        public array $apps,
        #[ListOf(RegistrationInfo::class)]
        public array $subscriptions,
        public ?StoreHealth $store = null,
        public ?DeployStatus $deploy = null,
        public ?ComponentStatus $component = null,
    ) {}
}
