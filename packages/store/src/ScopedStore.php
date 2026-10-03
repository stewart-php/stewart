<?php

declare(strict_types=1);

namespace Stewart\Store;

use Stewart\Contracts\Exception\StoreError;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\Store\Storable;
use Stewart\Contracts\Store\Store;
use Stewart\Contracts\Time\Duration;

final readonly class ScopedStore implements Store
{
    public function __construct(
        private StoreBackend $backend,
        private Keyspace $keyspace,
        private StoreValueCodec $codec,
    ) {}

    public function has(string $key): bool
    {
        return $this->backend->exists($this->keyspace->buildFullKey($key));
    }

    public function getInt(string $key): ?int
    {
        $raw = $this->readRawValue($key);

        return $raw === null ? null : $this->codec->decodeInt($key, $raw);
    }

    public function getFloat(string $key): ?float
    {
        $raw = $this->readRawValue($key);

        return $raw === null ? null : $this->codec->decodeFloat($key, $raw);
    }

    public function getString(string $key): ?string
    {
        $raw = $this->readRawValue($key);

        return $raw === null ? null : $this->codec->decodeString($key, $raw);
    }

    public function getBool(string $key): ?bool
    {
        $raw = $this->readRawValue($key);

        return $raw === null ? null : $this->codec->decodeBool($key, $raw);
    }

    /**
     * @template T of Storable
     *
     * @param class-string<T> $class
     * @return T|null
     */
    public function getObject(string $key, string $class): ?Storable
    {
        $raw = $this->readRawValue($key);

        return $raw === null ? null : $this->codec->decodeObject($key, $raw, $class);
    }

    public function listKeys(): array
    {
        $keys = array_map($this->keyspace->extractRelativeKey(...), $this->backend->keysWithPrefix($this->keyspace->prefix));
        sort($keys);

        return $keys;
    }

    public function set(string $key, bool|int|float|string|Storable $value, ?Duration $ttl = null): void
    {
        $ttl?->requireAtLeastOneMillisecond('a store TTL');
        $this->backend->write($this->keyspace->buildFullKey($key), $this->codec->encodeValue($key, $value), $ttl);
    }

    public function increment(string $key, int $by = 1): int
    {
        try {
            return $this->backend->increment($this->keyspace->buildFullKey($key), $by);
        } catch (StoreException $e) {
            $detail = $e->findDetail();

            throw $e->reason === StoreError::ValueNotIncrementable && $detail !== null
                ? StoreException::notIncrementable($key, $detail, $e)
                : $e;
        }
    }

    public function delete(string $key): void
    {
        $this->backend->remove($this->keyspace->buildFullKey($key));
    }

    public function clear(): void
    {
        $this->backend->removeByPrefix($this->keyspace->prefix);
    }

    private function readRawValue(string $key): ?string
    {
        return $this->backend->read($this->keyspace->buildFullKey($key));
    }
}
