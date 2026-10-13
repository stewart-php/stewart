<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Runtime\Ipc\Message\Bootstrap;
use Stewart\Store\GuardedStoreBackend;
use Stewart\Store\StoreBackendConnector;
use Stewart\Store\StoreDsn;
use Stewart\Store\StoreTiming;

final readonly class DsnStoreBackendOpener implements StoreBackendOpener
{
    public function __construct(private StoreBackendConnector $storeBackendConnector) {}

    public function openForBootstrap(Bootstrap $bootstrap): ?GuardedStoreBackend
    {
        $storeSettings = $bootstrap->store;

        if ($storeSettings === null) {
            return null;
        }

        return $this->storeBackendConnector->connectToBackend(
            StoreDsn::parse($storeSettings->dsn),
            new StoreTiming($storeSettings->timeout, $storeSettings->recoveryInterval),
        );
    }
}
