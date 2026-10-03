<?php

declare(strict_types=1);

namespace Stewart\Store\Tests\Fixtures;

use Stewart\Contracts\Time\Clock;
use Stewart\Store\StoreBackend;
use Stewart\Store\StoreBackendFactory;
use Stewart\Store\StoreDsn;
use Stewart\Store\StoreTiming;
use Stewart\Testing\Store\InMemoryStoreBackend;
use Stewart\Testing\Time\VirtualClock;

final class InMemoryBackendFactory implements StoreBackendFactory
{
    public ?StoreDsn $openedDsn = null;

    public ?StoreTiming $openedTiming = null;

    public function __construct(private readonly Clock $clock = new VirtualClock()) {}

    public function listSchemes(): array
    {
        return ['memory'];
    }

    public function createBackend(StoreDsn $dsn, StoreTiming $timing): StoreBackend
    {
        $this->openedDsn = $dsn;
        $this->openedTiming = $timing;

        return new InMemoryStoreBackend($this->clock);
    }
}
