<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Broker\Http\Collection\HttpListenerCollection;
use Stewart\Runtime\Config\HttpConfig;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Runtime\Http\Admin\AdminApiCodec;
use Stewart\Runtime\Http\Admin\AdminRequestHandler;
use Stewart\Runtime\Http\Admin\AppsAdminApi;

final readonly class HttpListenerFactory
{
    public function __construct(
        private HttpConfig $http,
        private ProbeRequestHandler $probeRequests,
        private AppsAdminApi $adminApps,
        private AdminApiCodec $adminCodec,
        private LoggerInterface $logger,
    ) {}

    /** @throws ConfigurationException */
    public function createHttpListeners(): HttpListenerCollection
    {
        $listeners = [];

        if ($this->http->listen !== null) {
            $listeners[] = new AmpHttpListener($this->http->listen, $this->probeRequests, HttpListenerRole::Probe, $this->logger);
        }

        $admin = $this->http->admin;

        if ($admin->listen !== null) {
            $requests = new AdminRequestHandler($this->adminApps, $this->adminCodec, $this->logger, $admin->token ?? throw ConfigurationException::adminTokenMissing());
            $listeners[] = new AmpHttpListener($admin->listen, $requests, HttpListenerRole::Admin, $this->logger);
        }

        return HttpListenerCollection::fromListeners($listeners);
    }
}
