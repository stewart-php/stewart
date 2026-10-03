<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Ipc;

use Amp\DeferredFuture;
use Stewart\Runtime\Ipc\Transport;
use Throwable;

final class FakeWorkerTransport implements Transport
{
    /** @var list<object> */
    public array $sent = [];

    /** @var list<object> */
    private array $inbox = [];

    /** @var list<Throwable> */
    private array $failures = [];

    /** @var DeferredFuture<object|null>|null */
    private ?DeferredFuture $waiting = null;

    private bool $closed = false;

    /** @var array<class-string, Throwable> */
    private array $refusedSends = [];

    public function send(object $message): void
    {
        $refusal = $this->refusedSends[$message::class] ?? null;

        if ($refusal !== null) {
            throw $refusal;
        }

        $this->sent[] = $message;
    }

    /** @param class-string $class */
    public function refuseSendsOf(string $class, Throwable $failure): void
    {
        $this->refusedSends[$class] = $failure;
    }

    public function receive(): ?object
    {
        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }

        if ($this->inbox !== []) {
            return array_shift($this->inbox);
        }

        if ($this->closed) {
            return null;
        }

        /** @var DeferredFuture<object|null> $waiting */
        $waiting = new DeferredFuture();
        $this->waiting = $waiting;

        return $waiting->getFuture()->await();
    }

    public function deliver(object $message): void
    {
        $waiting = $this->waiting;
        $this->waiting = null;

        if ($waiting === null) {
            $this->inbox[] = $message;
        } else {
            $waiting->complete($message);
        }
    }

    public function fail(Throwable $failure): void
    {
        $waiting = $this->waiting;
        $this->waiting = null;

        if ($waiting === null) {
            $this->failures[] = $failure;
        } else {
            $waiting->error($failure);
        }
    }

    public function hangUp(): void
    {
        $this->closed = true;

        $waiting = $this->waiting;
        $this->waiting = null;
        $waiting?->complete(null);
    }

    public function close(): void
    {
        $this->hangUp();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    public function listSentOfType(string $class): array
    {
        return array_values(array_filter($this->sent, static fn(object $message): bool => $message instanceof $class));
    }
}
