<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Stewart\Runtime\Broker\BrokerLifecycle;
use Stewart\Runtime\Broker\BrokerRun;
use Stewart\Runtime\Broker\DaemonStartTime;

final readonly class BootedBroker
{
    public function __construct(
        public BrokerLifecycle $lifecycle,
        public BrokerRun $run,
        public DaemonStartTime $startTime,
    ) {}
}
