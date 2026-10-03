<?php

declare(strict_types=1);

namespace Stewart\Contracts\Store;

use Stewart\Contracts\Exception\StoreException;

interface ReadableStore
{
    /** @throws StoreException */
    public function has(string $key): bool;

    /** @throws StoreException */
    public function getInt(string $key): ?int;

    /** @throws StoreException */
    public function getFloat(string $key): ?float;

    /** @throws StoreException */
    public function getString(string $key): ?string;

    /** @throws StoreException */
    public function getBool(string $key): ?bool;

    /**
     * @template T of Storable
     * @param class-string<T> $class
     * @return T|null
     * @throws StoreException
     */
    public function getObject(string $key, string $class): ?Storable;

    /**
     * @return list<string>
     * @throws StoreException
     */
    public function listKeys(): array;
}
