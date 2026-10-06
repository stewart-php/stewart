<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\ConnectionLimitingServerSocketFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketException;
use Amp\Sync\LocalSemaphore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stewart\Runtime\Broker\Http\ProbeListener;
use Stewart\Runtime\Config\HttpListenAddress;

final class HttpProbeServer implements ProbeListener
{
    private const int MAX_CONNECTIONS = 16;

    private const array PROBE_METHODS = ['GET', 'HEAD'];

    private ?SocketHttpServer $server = null;

    public function __construct(
        private readonly HttpListenAddress $address,
        private readonly ProbeRequestHandler $requests,
        private readonly LoggerInterface $logger,
    ) {}

    /** @throws SocketException */
    public function start(): void
    {
        // amphp logs its own limits and banners on every start; probe failures are logged by the handler.
        $amphpLogger = new NullLogger();
        $server = new SocketHttpServer(
            $amphpLogger,
            new ConnectionLimitingServerSocketFactory(new LocalSemaphore(self::MAX_CONNECTIONS)),
            new SocketClientFactory($amphpLogger),
            allowedMethods: self::PROBE_METHODS,
        );
        $port = $this->address->port;
        \assert($port >= 1 && $port <= 65535);
        $server->expose(new InternetAddress($this->address->ip, $port));
        $server->start($this->requests, new DefaultErrorHandler());
        $this->server = $server;

        $this->logger->info('Probe listener listening', ['address' => (string) $this->address]);
    }

    public function stop(): void
    {
        $this->server?->stop();
        $this->server = null;
    }
}
