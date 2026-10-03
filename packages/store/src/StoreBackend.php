<?php

declare(strict_types=1);

namespace Stewart\Store;

use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\Time\Duration;

interface StoreBackend
{
    /** @throws StoreException */
    public function read(string $key): ?string;

    /** @throws StoreException */
    public function write(string $key, string $value, ?Duration $ttl): void;

    /** @throws StoreException */
    public function remove(string $key): void;

    /** @throws StoreException */
    public function exists(string $key): bool;

    /** @throws StoreException */
    public function increment(string $key, int $by): int;

    /**
     * @return list<string>
     * @throws StoreException
     */
    public function keysWithPrefix(string $prefix): array;

    /** @throws StoreException */
    public function removeByPrefix(string $prefix): void;

    /** @throws StoreException */
    public function probe(): void;
}
