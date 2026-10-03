<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Store\StoreDsn;
use Stewart\Store\StorePrefix;

final readonly class PersistenceConfig
{
    private const int SHORTEST_STORE_TIMING_MILLISECONDS = 100;

    public function __construct(
        public StoreDsn $url,
        public StorePrefix $prefix,
        public Duration $timeout,
        public Duration $recoveryInterval,
    ) {}

    /** @throws ConfigurationException */
    public static function fromSection(ConfigSection $persistence): ?self
    {
        $url = $persistence->findString('url');

        if ($url === null || $url === '') {
            return null;
        }

        $shortest = Duration::milliseconds(self::SHORTEST_STORE_TIMING_MILLISECONDS);

        return new self(
            url: $persistence->readParsedValue('url', StoreDsn::parse(...)),
            prefix: $persistence->readParsedValue('prefix', static fn(string $prefix): StorePrefix => new StorePrefix($prefix)),
            timeout: $persistence->readDuration('timeout', $shortest),
            recoveryInterval: $persistence->readDuration('recovery_interval', $shortest),
        );
    }
}
