<?php

declare(strict_types=1);

namespace Stewart\Store;

use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\Time\Clock;
use Stewart\Store\Exception\StoreSetupException;

final readonly class StoreBackendConnector
{
    public function __construct(
        private StoreBackendRegistry $storeBackends,
        private Clock $clock,
    ) {}

    /** @throws StoreException|StoreSetupException */
    public function connectToBackend(StoreDsn $dsn, StoreTiming $timing): GuardedStoreBackend
    {
        return new GuardedStoreBackend($this->storeBackends->requireFactoryForDsn($dsn)->createBackend($dsn, $timing), $dsn, $timing, $this->clock);
    }
}
