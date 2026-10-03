<?php

declare(strict_types=1);

namespace Stewart\Store;

use Stewart\Contracts\Store\ReadableStore;
use Stewart\Contracts\Store\Storable;

final readonly class ReadOnlyStore implements ReadableStore
{
    public function __construct(private ReadableStore $inner) {}

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }

    public function getInt(string $key): ?int
    {
        return $this->inner->getInt($key);
    }

    public function getFloat(string $key): ?float
    {
        return $this->inner->getFloat($key);
    }

    public function getString(string $key): ?string
    {
        return $this->inner->getString($key);
    }

    public function getBool(string $key): ?bool
    {
        return $this->inner->getBool($key);
    }

    /**
     * @template T of Storable
     *
     * @param class-string<T> $class
     * @return T|null
     */
    public function getObject(string $key, string $class): ?Storable
    {
        return $this->inner->getObject($key, $class);
    }

    public function listKeys(): array
    {
        return $this->inner->listKeys();
    }
}
