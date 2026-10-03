<?php

declare(strict_types=1);

namespace Stewart\Store;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\Store\ReadableStore;
use Stewart\Contracts\Store\Store;

final readonly class DisabledStores implements Stores
{
    public function openAppStore(AppId $appId): Store
    {
        throw StoreException::notConfigured();
    }

    public function openGlobalStore(): Store
    {
        throw StoreException::notConfigured();
    }

    public function openPeerStoreForReading(AppId $appId): ReadableStore
    {
        throw StoreException::notConfigured();
    }
}
