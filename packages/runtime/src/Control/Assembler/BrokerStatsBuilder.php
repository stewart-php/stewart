<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Assembler;

use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Control\Protocol\Status\BrokerStats;

final readonly class BrokerStatsBuilder
{
    public function __construct(
        private WorkerSlotRegistry $slots,
        private HaCallSlots $callSlots,
        private SubscriptionRegistry $registry,
    ) {}

    public function buildBrokerStats(): BrokerStats
    {
        return new BrokerStats(
            workers: $this->slots->countSpawnedSlots(),
            liveWorkers: $this->slots->countLiveWorkers(),
            inFlightServiceCalls: $this->callSlots->inFlight,
            refusedServiceCalls: $this->callSlots->refusedCalls,
            routing: $this->registry->getRoutingStats(),
        );
    }
}
