<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Amp\DeferredFuture;
use Amp\Future;
use Closure;
use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Ipc\Message\RequestFailed;
use Stewart\Runtime\Model\CorrelationId;
use Throwable;

/** @template TResult of object */
final readonly class PendingRequest
{
    /** @var DeferredFuture<TResult> */
    private DeferredFuture $deferred;

    /**
     * @param class-string<TResult> $resultClass
     * @param Closure(string): StewartException<ExceptionReason> $unreachable
     */
    public function __construct(
        public CorrelationId $correlationId,
        private string $resultClass,
        private Closure $unreachable,
    ) {
        /** @var DeferredFuture<TResult> $deferred */
        $deferred = new DeferredFuture();
        $this->deferred = $deferred;
    }

    /** @return Future<TResult> */
    public function getFuture(): Future
    {
        return $this->deferred->getFuture();
    }

    public function complete(object $result): void
    {
        if (!$result instanceof $this->resultClass) {
            $this->failAsUnreachable(\sprintf('the broker answered with %s', get_debug_type($result)));

            return;
        }

        $this->deferred->complete($result);
    }

    public function failFromWire(RequestFailed $failure): void
    {
        $fallback = ($this->unreachable)($failure->message);
        $reason = $fallback->reason::tryFrom($failure->details->reason) ?? $fallback->reason;

        $this->fail($fallback::restoreFromWire($reason, $failure->message, $failure->details->context));
    }

    public function failAsUnreachable(string $detail): void
    {
        $this->fail(($this->unreachable)($detail));
    }

    public function fail(Throwable $error): void
    {
        $this->deferred->error($error);
    }
}
