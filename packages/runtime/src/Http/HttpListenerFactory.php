<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Broker\Http\Collection\HttpListenerCollection;
use Stewart\Runtime\Config\HttpConfig;

final readonly class HttpListenerFactory
{
    public function __construct(
        private HttpConfig $http,
        private ProbeRequestHandler $probeRequests,
        private LoggerInterface $logger,
    ) {}

    public function createHttpListeners(): HttpListenerCollection
    {
        $listeners = [];

        if ($this->http->listen !== null) {
            $listeners[] = new AmpHttpListener($this->http->listen, $this->probeRequests, HttpListenerRole::Probe, $this->logger);
        }

        return HttpListenerCollection::fromListeners($listeners);
    }
}
