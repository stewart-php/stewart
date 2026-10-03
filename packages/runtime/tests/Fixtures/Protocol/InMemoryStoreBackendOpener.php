<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Ipc\StoreSettings;
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

    public function openForStoreSettings(?StoreSettings $workerStoreSettings): ?GuardedStoreBackend
    {
        if ($workerStoreSettings === null) {
            return null;
        }

        return new GuardedStoreBackend(
            $this->backend,
            StoreDsn::parse($workerStoreSettings->dsn),
            new StoreTiming($workerStoreSettings->timeout, $workerStoreSettings->recoveryInterval),
            $this->clock,
        );
    }
}
