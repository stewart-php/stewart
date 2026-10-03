<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Broker\BrokerLifecycle;

final readonly class PreparedDaemon
{
    public function __construct(
        public BrokerLifecycle $broker,
        public LoggerInterface $logger,
    ) {}
}
