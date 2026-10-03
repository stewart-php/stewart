<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Runtime\Ipc\StoreSettings;
use Stewart\Store\GuardedStoreBackend;
use Stewart\Store\StoreBackendConnector;
use Stewart\Store\StoreDsn;
use Stewart\Store\StoreTiming;

final readonly class DsnStoreBackendOpener implements StoreBackendOpener
{
    public function __construct(private StoreBackendConnector $storeBackendConnector) {}

    public function openForStoreSettings(?StoreSettings $workerStoreSettings): ?GuardedStoreBackend
    {
        if ($workerStoreSettings === null) {
            return null;
        }

        return $this->storeBackendConnector->connectToBackend(
            StoreDsn::parse($workerStoreSettings->dsn),
            new StoreTiming($workerStoreSettings->timeout, $workerStoreSettings->recoveryInterval),
        );
    }
}
