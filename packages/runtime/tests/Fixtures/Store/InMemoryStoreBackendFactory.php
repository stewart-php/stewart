<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Store;

use Stewart\Runtime\Time\SystemClock;
use Stewart\Store\StoreBackend;
use Stewart\Store\StoreBackendFactory;
use Stewart\Store\StoreDsn;
use Stewart\Store\StoreTiming;
use Stewart\Testing\Store\InMemoryStoreBackend;

final class InMemoryStoreBackendFactory implements StoreBackendFactory
{
    public readonly InMemoryStoreBackend $backend;

    public int $opened = 0;

    public function __construct()
    {
        $this->backend = new InMemoryStoreBackend(SystemClock::inUtc());
    }

    public function listSchemes(): array
    {
        return ['memory'];
    }

    public function createBackend(StoreDsn $dsn, StoreTiming $timing): StoreBackend
    {
        ++$this->opened;

        return $this->backend;
    }
}
