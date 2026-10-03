<?php

declare(strict_types=1);

namespace Stewart\Store;

use Stewart\Store\Collection\StoreBackendFactoryCollection;
use Stewart\Store\Exception\StoreSetupException;

final readonly class StoreBackendRegistry
{
    private StoreBackendFactoryCollection $factories;

    /** @param iterable<StoreBackendFactory> $factories */
    public function __construct(iterable $factories = [])
    {
        $this->factories = StoreBackendFactoryCollection::keyedByScheme($factories);
    }

    /** @throws StoreSetupException */
    public function requireFactoryForDsn(StoreDsn $dsn): StoreBackendFactory
    {
        return $this->factories->find($dsn->scheme) ?? throw StoreSetupException::schemeUnsupported($dsn->scheme, $this->factories->listSchemes());
    }
}
