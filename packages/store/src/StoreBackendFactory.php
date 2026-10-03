<?php

declare(strict_types=1);

namespace Stewart\Store;

use Stewart\Contracts\Exception\StoreException;

interface StoreBackendFactory
{
    /** @return list<string> */
    public function listSchemes(): array;

    /** @throws StoreException */
    public function createBackend(StoreDsn $dsn, StoreTiming $timing): StoreBackend;
}
