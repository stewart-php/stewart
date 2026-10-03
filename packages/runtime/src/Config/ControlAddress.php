<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Amp\Socket\SocketAddress;
use Stewart\Runtime\Exception\ConfigurationException;
use Stringable;

abstract readonly class ControlAddress implements Stringable
{
    public const string DEFAULT_SOCKET = 'var/run/stewart.sock';

    /** @throws ConfigurationException */
    public static function parse(string $address): self
    {
        if (preg_match('~\Aunix://(.+)\z~', $address, $unix) === 1) {
            return new UnixControlAddress($unix[1]);
        }

        if (preg_match('~\Atcp://(\[[0-9a-fA-F:.]+\]|[^:/\[\]]+):([0-9]{1,5})\z~', $address, $tcp) === 1) {
            return new TcpControlAddress($tcp[1], (int) $tcp[2]);
        }

        throw ConfigurationException::valueInvalid(
            $address,
            'a control address; use unix://path, tcp://host:port or "off"',
        );
    }

    abstract public function isLoopback(): bool;

    abstract public function toConnectableAddress(): self;

    /** @throws ConfigurationException */
    abstract public function resolveSocketAddress(ProjectRoot $projectRoot): SocketAddress|string;
}
