<?php

declare(strict_types=1);

namespace Stewart\Store;

use Closure;
use Stewart\Contracts\Exception\StoreError;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\MonotonicTime;

final class GuardedStoreBackend implements StoreBackend
{
    private const array PAUSING_FAILURES = [StoreError::Unreachable, StoreError::TimedOut];

    private ?MonotonicTime $pausedUntil = null;

    private bool $lastOperationAnswered = true;

    private ?string $lastFailure = null;

    private ?Instant $lastFailureAt = null;

    public function __construct(
        private readonly StoreBackend $backend,
        private readonly StoreDsn $dsn,
        private readonly StoreTiming $timing,
        private readonly Clock $clock,
    ) {}

    public function read(string $key): ?string
    {
        return $this->runGuarded(fn(): ?string => $this->backend->read($key));
    }

    public function write(string $key, string $value, ?Duration $ttl): void
    {
        $this->runGuarded(fn() => $this->backend->write($key, $value, $ttl));
    }

    public function remove(string $key): void
    {
        $this->runGuarded(fn() => $this->backend->remove($key));
    }

    public function exists(string $key): bool
    {
        return $this->runGuarded(fn(): bool => $this->backend->exists($key));
    }

    public function increment(string $key, int $by): int
    {
        return $this->runGuarded(fn(): int => $this->backend->increment($key, $by));
    }

    public function keysWithPrefix(string $prefix): array
    {
        return $this->runGuarded(fn(): array => $this->backend->keysWithPrefix($prefix));
    }

    public function removeByPrefix(string $prefix): void
    {
        $this->runGuarded(fn() => $this->backend->removeByPrefix($prefix));
    }

    public function probe(): void
    {
        $this->runGuarded($this->backend->probe(...));
    }

    public function getHealth(): StoreHealth
    {
        return new StoreHealth($this->lastOperationAnswered, $this->lastFailure, $this->lastFailureAt);
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     * @throws StoreException
     */
    private function runGuarded(Closure $operation): mixed
    {
        if ($this->isPaused()) {
            throw StoreException::recentlyFailed((string) $this->dsn, $this->lastFailure ?? '');
        }

        try {
            $result = $operation();
        } catch (StoreException $e) {
            if (\in_array($e->reason, self::PAUSING_FAILURES, true)) {
                $this->pauseAfter($e);
            } else {
                $this->lastOperationAnswered = true;
            }

            throw $e;
        }

        $this->pausedUntil = null;
        $this->lastOperationAnswered = true;

        return $result;
    }

    private function pauseAfter(StoreException $failure): void
    {
        $this->pausedUntil = $this->clock->getMonotonicTime()->plus($this->timing->recoveryInterval);
        $this->lastOperationAnswered = false;
        $this->lastFailure = $failure->getMessage();
        $this->lastFailureAt = $this->clock->getNow();
    }

    private function isPaused(): bool
    {
        return $this->pausedUntil?->isAfter($this->clock->getMonotonicTime()) === true;
    }
}
