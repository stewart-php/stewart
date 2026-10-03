<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use LogicException;
use Stewart\Runtime\Ipc\Message\Bootstrap;
use Stewart\Store\DisabledStores;
use Stewart\Store\Exception\StoreSetupException;
use Stewart\Store\KeyspacedStores;
use Stewart\Store\StoreBackend;
use Stewart\Store\StorePrefix;
use Stewart\Store\Stores;
use Stewart\Store\StoreValueCodec;
use Stewart\Support\Text\ClosestNameFinder;

final readonly class WorkerStoresFactory
{
    public function __construct(
        private Bootstrap $bootstrap,
        private StoreValueCodec $codec,
        private ClosestNameFinder $closestNameFinder,
        private ?StoreBackend $storeBackend = null,
    ) {}

    /** @throws StoreSetupException */
    public function createStores(): Stores
    {
        if ($this->storeBackend === null) {
            return new DisabledStores();
        }

        $prefix = $this->bootstrap->store->prefix ?? throw new LogicException('A store backend was opened without store settings.');

        return new KeyspacedStores($this->storeBackend, new StorePrefix($prefix), $this->bootstrap->knownAppIds->collection, $this->codec, $this->closestNameFinder);
    }
}
