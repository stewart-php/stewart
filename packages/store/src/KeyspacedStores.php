<?php

declare(strict_types=1);

namespace Stewart\Store;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Store\ReadableStore;
use Stewart\Contracts\Store\Store;
use Stewart\Store\Exception\StoreSetupException;
use Stewart\Support\Text\ClosestNameFinder;

final readonly class KeyspacedStores implements Stores
{
    public function __construct(
        private StoreBackend $backend,
        private StorePrefix $prefix,
        private AppIdCollection $knownAppIds,
        private StoreValueCodec $codec,
        private ClosestNameFinder $closestNameFinder,
    ) {}

    public function openAppStore(AppId $appId): Store
    {
        return $this->createScopedStore(Keyspace::forApp($this->prefix, $appId));
    }

    public function openGlobalStore(): Store
    {
        return $this->createScopedStore(Keyspace::forGlobalScope($this->prefix));
    }

    public function openPeerStoreForReading(AppId $appId): ReadableStore
    {
        if (!$this->knownAppIds->containsId($appId)) {
            throw StoreSetupException::unknownPeerApp($appId, $this->closestNameFinder->findClosestName($appId->value, $this->knownAppIds->toStrings()));
        }

        return new ReadOnlyStore($this->createScopedStore(Keyspace::forApp($this->prefix, $appId)));
    }

    private function createScopedStore(Keyspace $keyspace): ScopedStore
    {
        return new ScopedStore($this->backend, $keyspace, $this->codec);
    }
}
