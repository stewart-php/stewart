<?php

declare(strict_types=1);

namespace Stewart\Testing\Store;

use Closure;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Store\StoreBackend;

final class InMemoryStoreBackend implements StoreBackend
{
    /** @var array<string, StoredEntry> */
    private array $entries = [];

    private ?string $failure = null;

    /** @var (Closure(): void)|null */
    public ?Closure $duringProbe = null;

    public function __construct(private readonly Clock $clock) {}

    public function simulateOutage(string $detail): void
    {
        $this->failure = $detail;
    }

    public function endOutage(): void
    {
        $this->failure = null;
    }

    public function read(string $key): ?string
    {
        return $this->findLiveEntry($key)?->value;
    }

    public function write(string $key, string $value, ?Duration $ttl): void
    {
        $this->failDuringOutage();

        $this->entries[$key] = new StoredEntry($value, $this->computeExpiryFor($ttl));
    }

    public function remove(string $key): void
    {
        $this->failDuringOutage();

        unset($this->entries[$key]);
    }

    public function exists(string $key): bool
    {
        return $this->findLiveEntry($key) !== null;
    }

    public function increment(string $key, int $by): int
    {
        $entry = $this->findLiveEntry($key);

        if ($entry === null) {
            $this->entries[$key] = new StoredEntry((string) $by, null);

            return $by;
        }

        if (preg_match('/\A-?(0|[1-9]\d*)\z/', $entry->value) !== 1) {
            throw StoreException::valueNotIncrementable('the value is not a whole number');
        }

        $current = (int) $entry->value;

        if (($by > 0 && $current > \PHP_INT_MAX - $by) || ($by < 0 && $current < \PHP_INT_MIN - $by)) {
            throw StoreException::valueNotIncrementable('the result would overflow');
        }

        // The expiry is deliberately carried over: counting must not extend a lifetime.
        $this->entries[$key] = new StoredEntry((string) ($current + $by), $entry->expiresAtMilliseconds);

        return $current + $by;
    }

    public function keysWithPrefix(string $prefix): array
    {
        $this->failDuringOutage();

        $found = [];

        foreach (array_keys($this->entries) as $key) {
            if (str_starts_with($key, $prefix) && $this->findLiveEntry($key) !== null) {
                $found[] = $key;
            }
        }

        return $found;
    }

    public function removeByPrefix(string $prefix): void
    {
        foreach ($this->keysWithPrefix($prefix) as $key) {
            unset($this->entries[$key]);
        }
    }

    public function probe(): void
    {
        if ($this->duringProbe !== null) {
            ($this->duringProbe)();
        }

        $this->failDuringOutage();
    }

    private function findLiveEntry(string $key): ?StoredEntry
    {
        $this->failDuringOutage();

        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry->expiresAtMilliseconds !== null && $entry->expiresAtMilliseconds <= $this->readNowInMilliseconds()) {
            unset($this->entries[$key]);

            return null;
        }

        return $entry;
    }

    private function computeExpiryFor(?Duration $ttl): ?int
    {
        return $ttl === null ? null : $this->readNowInMilliseconds() + $ttl->toMilliseconds();
    }

    private function readNowInMilliseconds(): int
    {
        return intdiv($this->clock->getNow()->toEpochMicroseconds(), 1_000);
    }

    private function failDuringOutage(): void
    {
        if ($this->failure !== null) {
            throw StoreException::unreachable('memory', $this->failure);
        }
    }
}
