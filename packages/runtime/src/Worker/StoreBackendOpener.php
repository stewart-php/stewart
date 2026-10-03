<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Contracts\Exception\StoreException;
use Stewart\Runtime\Ipc\StoreSettings;
use Stewart\Store\Exception\StoreSetupException;
use Stewart\Store\GuardedStoreBackend;

interface StoreBackendOpener
{
    /** @throws StoreException|StoreSetupException */
    public function openForStoreSettings(?StoreSettings $workerStoreSettings): ?GuardedStoreBackend;
}
