<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\ConnectionLimitingServerSocketFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketException;
use Amp\Sync\LocalSemaphore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stewart\Runtime\Broker\Http\HttpListener;
use Stewart\Runtime\Config\HttpListenAddress;

final class AmpHttpListener implements HttpListener
{
    private const int MAX_CONNECTIONS = 16;

    private ?SocketHttpServer $server = null;

    public function __construct(
        private readonly HttpListenAddress $address,
        private readonly RequestHandler $requests,
        private readonly HttpListenerRole $role,
        private readonly LoggerInterface $logger,
    ) {}

    /** @throws SocketException */
    public function start(): void
    {
        // amphp logs its own limits and banners on every start; request failures are logged by the handler.
        $amphpLogger = new NullLogger();
        $server = new SocketHttpServer(
            $amphpLogger,
            new ConnectionLimitingServerSocketFactory(new LocalSemaphore(self::MAX_CONNECTIONS)),
            new SocketClientFactory($amphpLogger),
            allowedMethods: $this->role->listAllowedMethods(),
        );
        $server->expose(new InternetAddress($this->address->ip, $this->address->port));
        $server->start($this->requests, new DefaultErrorHandler());
        $this->server = $server;

        $this->logger->info('HTTP listener listening', ['listener' => $this->role->value, 'address' => (string) $this->address]);
    }

    public function stop(): void
    {
        $this->server?->stop();
        $this->server = null;
    }
}
