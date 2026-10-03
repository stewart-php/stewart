<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Runtime\Model\RoutingStats;

final readonly class BrokerStats
{
    public function __construct(
        public int $workers,
        public int $liveWorkers,
        public int $inFlightServiceCalls,
        public int $refusedServiceCalls,
        public RoutingStats $routing,
    ) {}
}
