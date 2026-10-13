<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Ipc\Message\Bootstrap;
use Stewart\Runtime\Worker\StoreBackendOpener;
use Stewart\Store\GuardedStoreBackend;
use Stewart\Store\StoreBackend;
use Stewart\Store\StoreDsn;
use Stewart\Store\StoreTiming;

final readonly class InMemoryStoreBackendOpener implements StoreBackendOpener
{
    public function __construct(
        private StoreBackend $backend,
        private Clock $clock,
    ) {}

    public function openForBootstrap(Bootstrap $bootstrap): ?GuardedStoreBackend
    {
        $storeSettings = $bootstrap->store;

        if ($storeSettings === null) {
            return null;
        }

        return new GuardedStoreBackend(
            $this->backend,
            StoreDsn::parse($storeSettings->dsn),
            new StoreTiming($storeSettings->timeout, $storeSettings->recoveryInterval),
            $this->clock,
        );
    }
}
