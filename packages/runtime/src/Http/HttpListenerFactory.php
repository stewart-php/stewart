<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Admin\RuntimeAdmin;
use Stewart\Runtime\Broker\Http\Collection\HttpListenerCollection;
use Stewart\Runtime\Config\HttpConfig;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Runtime\Http\Admin\AdminApiCodec;
use Stewart\Runtime\Http\Admin\AdminRequestHandler;
use Stewart\Runtime\Metrics\RuntimeMetricsExporter;

final readonly class HttpListenerFactory
{
    public function __construct(
        private HttpConfig $http,
        private ProbeRequestHandler $probeRequests,
        private RuntimeAdmin $admin,
        private AdminApiCodec $adminCodec,
        private RuntimeMetricsExporter $metricsExporter,
        private LoggerInterface $logger,
    ) {}

    /** @throws ConfigurationException */
    public function createHttpListeners(): HttpListenerCollection
    {
        $this->http->requireValidListeners();
        $listeners = [];

        if ($this->http->listen !== null) {
            $listeners[] = new AmpHttpListener($this->http->listen, $this->probeRequests, HttpListenerRole::Probe, $this->logger);
        }

        $admin = $this->http->admin;

        if ($admin->listen !== null) {
            $requests = new AdminRequestHandler(
                $this->admin,
                $this->adminCodec,
                $this->logger,
                new BearerTokenCheck($admin->requireToken()),
                new UnauthorizedRequestWarnings(HttpListenerRole::Admin, $this->logger),
            );
            $listeners[] = new AmpHttpListener($admin->listen, $requests, HttpListenerRole::Admin, $this->logger);
        }

        $metrics = $this->http->metrics;

        if ($metrics->listen !== null) {
            if ($metrics->token === null) {
                $this->logger->info('The metrics port has no token, so anyone who reaches it reads the metrics', ['address' => (string) $metrics->listen]);
            }

            $requests = new MetricsRequestHandler(
                $this->metricsExporter,
                new BearerTokenCheck($metrics->token),
                new UnauthorizedRequestWarnings(HttpListenerRole::Metrics, $this->logger),
                $this->logger,
            );
            $listeners[] = new AmpHttpListener($metrics->listen, $requests, HttpListenerRole::Metrics, $this->logger);
        }

        return HttpListenerCollection::fromList($listeners);
    }
}
