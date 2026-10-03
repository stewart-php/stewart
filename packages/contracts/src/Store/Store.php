<?php

declare(strict_types=1);

namespace Stewart\Contracts\Store;

use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\Exception\TimeException;
use Stewart\Contracts\Time\Duration;

interface Store extends ReadableStore
{
    /**
     * @throws StoreException
     * @throws TimeException
     */
    public function set(string $key, bool|int|float|string|Storable $value, ?Duration $ttl = null): void;

    /** @throws StoreException */
    public function increment(string $key, int $by = 1): int;

    /** @throws StoreException */
    public function delete(string $key): void;

    /** @throws StoreException */
    public function clear(): void;
}
