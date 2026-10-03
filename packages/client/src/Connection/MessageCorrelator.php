<?php

declare(strict_types=1);

namespace Stewart\Client\Connection;

use Amp\DeferredFuture;
use Amp\Future;
use Throwable;

/** @internal */
final class MessageCorrelator
{
    private int $nextId = 1;

    /** @var array<int, DeferredFuture<array<string, mixed>>> */
    private array $pending = [];

    public function nextId(): int
    {
        return $this->nextId++;
    }

    /** @return Future<array<string, mixed>> */
    public function expect(int $id): Future
    {
        /** @var DeferredFuture<array<string, mixed>> $deferred */
        $deferred = new DeferredFuture();
        $this->pending[$id] = $deferred;

        return $deferred->getFuture()->ignore();
    }

    /** @param array<string, mixed> $result */
    public function resolve(int $id, array $result): void
    {
        $deferred = $this->pending[$id] ?? null;

        if ($deferred === null) {
            return;
        }

        unset($this->pending[$id]);
        $deferred->complete($result);
    }

    public function forget(int $id): void
    {
        unset($this->pending[$id]);
    }

    public function failAll(Throwable $error): int
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $deferred) {
            $deferred->error($error);
        }

        return \count($pending);
    }
}
