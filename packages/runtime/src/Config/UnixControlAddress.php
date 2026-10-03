<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Amp\Socket\UnixAddress;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class UnixControlAddress extends ControlAddress
{
    // Linux sun_path is 108 bytes, less a terminating NUL and a temp suffix.
    private const int MAX_SOCKET_PATH_BYTES = 100;

    /** @throws ConfigurationException */
    public function __construct(public string $path)
    {
        if ($path === '') {
            throw ConfigurationException::valueInvalid('unix://', 'a unix control address with a path');
        }
    }

    /** @throws ConfigurationException */
    public function resolveSocketPath(ProjectRoot $projectRoot): string
    {
        $absolutePath = $projectRoot->resolvePath($this->path);

        if (\strlen($absolutePath) > self::MAX_SOCKET_PATH_BYTES) {
            throw ConfigurationException::valueInvalid((string) $this, \sprintf(
                'a unix control address; the resolved path %s is %d bytes, the limit is %d',
                $absolutePath,
                \strlen($absolutePath),
                self::MAX_SOCKET_PATH_BYTES,
            ));
        }

        return $absolutePath;
    }

    public function isLoopback(): bool
    {
        return true;
    }

    public function toConnectableAddress(): self
    {
        return $this;
    }

    /** @throws ConfigurationException */
    public function resolveSocketAddress(ProjectRoot $projectRoot): UnixAddress
    {
        return new UnixAddress($this->resolveSocketPath($projectRoot));
    }

    public function __toString(): string
    {
        return 'unix://' . $this->path;
    }
}
