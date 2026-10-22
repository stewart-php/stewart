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
     * @param list<ScheduleStatus> $schedules
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
        public ?ReloadStatus $reload = null,
        #[ListOf(ScheduleStatus::class)]
        public array $schedules = [],
        public ?MqttStatus $mqtt = null,
        public ?GeneratedCodeStatus $generated = null,
    ) {}

    /** @param list<string> $appIds */
    public function withOnlyApps(array $appIds): self
    {
        return new self(
            takenAt: $this->takenAt,
            daemon: $this->daemon,
            connection: $this->connection,
            broker: $this->broker,
            workers: $this->workers,
            apps: array_values(array_filter($this->apps, static fn(AppStatus $app): bool => \in_array($app->id, $appIds, true))),
            subscriptions: array_values(array_filter($this->subscriptions, static fn(RegistrationInfo $subscription): bool => \in_array($subscription->appId, $appIds, true))),
            store: $this->store,
            deploy: $this->deploy,
            component: $this->component,
            reload: $this->reload,
            schedules: array_values(array_filter($this->schedules, static fn(ScheduleStatus $schedule): bool => \in_array($schedule->appId, $appIds, true))),
            mqtt: $this->mqtt,
            generated: $this->generated,
        );
    }
}
