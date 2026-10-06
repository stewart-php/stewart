<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Runtime\Exception\ConfigurationException;
use Stringable;

final readonly class HttpListenAddress implements Stringable
{
    /** @throws ConfigurationException */
    public function __construct(
        public string $ip,
        public int $port,
    ) {
        if (filter_var($ip, \FILTER_VALIDATE_IP) === false) {
            throw ConfigurationException::valueInvalid((string) $this, 'an HTTP listen address with an IP address, such as 0.0.0.0 or [::]');
        }

        if ($port < 1 || $port > 65535) {
            throw ConfigurationException::valueInvalid((string) $this, 'an HTTP listen address with a port in 1-65535');
        }
    }

    /** @throws ConfigurationException */
    public static function parse(string $address): self
    {
        if (preg_match('~\Atcp://(?:\[([0-9a-fA-F:.]+)\]|([^:/\[\]]+)):([0-9]{1,5})\z~', $address, $tcp) === 1) {
            return new self($tcp[1] !== '' ? $tcp[1] : $tcp[2], (int) $tcp[3]);
        }

        throw ConfigurationException::valueInvalid($address, 'an HTTP listen address; use tcp://ip:port or "off"');
    }

    public function __toString(): string
    {
        return \sprintf(str_contains($this->ip, ':') ? 'tcp://[%s]:%d' : 'tcp://%s:%d', $this->ip, $this->port);
    }
}
