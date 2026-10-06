<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Runtime\Exception\ConfigurationException;
use Stringable;

final readonly class HttpListenAddress implements Stringable
{
    /** @var int<1, 65535> */
    public int $port;

    /** @throws ConfigurationException */
    public function __construct(
        public string $ip,
        int $port,
    ) {
        if (filter_var($ip, \FILTER_VALIDATE_IP) === false) {
            throw ConfigurationException::valueInvalid(self::formatAddress($ip, $port), 'an HTTP listen address with an IP address, such as 0.0.0.0 or [::]');
        }

        if ($port < 1 || $port > 65535) {
            throw ConfigurationException::valueInvalid(self::formatAddress($ip, $port), 'an HTTP listen address with a port in 1-65535');
        }

        $this->port = $port;
    }

    /** @throws ConfigurationException */
    public static function parse(string $address): self
    {
        if (preg_match('~\Atcp://(?:\[([0-9a-fA-F:.]+)\]|([^:/\[\]]+)):([0-9]{1,5})\z~', trim($address), $tcp) === 1) {
            return new self($tcp[1] !== '' ? $tcp[1] : $tcp[2], (int) $tcp[3]);
        }

        throw ConfigurationException::valueInvalid($address, 'an HTTP listen address; use tcp://ip:port or "off"');
    }

    public function __toString(): string
    {
        return self::formatAddress($this->ip, $this->port);
    }

    private static function formatAddress(string $ip, int $port): string
    {
        return \sprintf(str_contains($ip, ':') ? 'tcp://[%s]:%d' : 'tcp://%s:%d', $ip, $port);
    }
}
