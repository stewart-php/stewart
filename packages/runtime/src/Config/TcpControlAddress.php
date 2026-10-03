<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Runtime\Exception\ConfigurationException;

final readonly class TcpControlAddress extends ControlAddress
{
    private const array WILDCARD_HOSTS = ['0.0.0.0', '::', '[::]', '*'];

    private const array LOOPBACK_HOSTS = ['127.0.0.1', '::1', '[::1]', 'localhost'];

    /** @throws ConfigurationException */
    public function __construct(
        public string $host,
        public int $port,
    ) {
        if ($host === '') {
            throw ConfigurationException::valueInvalid((string) $this, 'a TCP control address with a host and a port');
        }

        if ($port < 1 || $port > 65535) {
            throw ConfigurationException::valueInvalid((string) $this, 'a TCP control address with a port in 1-65535');
        }
    }

    public function isLoopback(): bool
    {
        return \in_array($this->host, self::LOOPBACK_HOSTS, true);
    }

    public function toConnectableAddress(): self
    {
        return \in_array($this->host, self::WILDCARD_HOSTS, true) ? new self('127.0.0.1', $this->port) : $this;
    }

    public function resolveSocketAddress(ProjectRoot $projectRoot): string
    {
        return (string) $this;
    }

    public function __toString(): string
    {
        return \sprintf('tcp://%s:%d', $this->host, $this->port);
    }
}
