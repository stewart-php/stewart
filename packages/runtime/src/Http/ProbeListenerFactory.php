<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Broker\Http\DisabledProbeListener;
use Stewart\Runtime\Broker\Http\ProbeListener;
use Stewart\Runtime\Config\HttpConfig;

final readonly class ProbeListenerFactory
{
    public function __construct(
        private HttpConfig $http,
        private ProbeRequestHandler $requests,
        private LoggerInterface $logger,
    ) {}

    public function createProbeListener(): ProbeListener
    {
        if ($this->http->listen === null) {
            return new DisabledProbeListener();
        }

        return new HttpProbeServer($this->http->listen, $this->requests, $this->logger);
    }
}
