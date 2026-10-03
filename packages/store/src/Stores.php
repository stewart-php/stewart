<?php

declare(strict_types=1);

namespace Stewart\Store;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\Store\ReadableStore;
use Stewart\Contracts\Store\Store;
use Stewart\Store\Exception\StoreSetupException;

interface Stores
{
    /** @throws StoreException */
    public function openAppStore(AppId $appId): Store;

    /** @throws StoreException */
    public function openGlobalStore(): Store;

    /** @throws StoreSetupException|StoreException */
    public function openPeerStoreForReading(AppId $appId): ReadableStore;
}
