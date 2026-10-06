<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Amp\DeferredFuture;
use Amp\Future;
use Stewart\Runtime\Model\CorrelationId;
use Throwable;

/** @template TResult of object */
final readonly class PendingRequest
{
    /** @var DeferredFuture<TResult> */
    private DeferredFuture $deferred;

    /** @param PendingRequestSubject<TResult> $subject */
    public function __construct(
        public CorrelationId $correlationId,
        public PendingRequestSubject $subject,
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
        $resultClass = $this->subject->getResultClass();

        if (!$result instanceof $resultClass) {
            $this->fail($this->subject->createUnreachableFailure(\sprintf('the broker answered with %s', get_debug_type($result))));

            return;
        }

        $this->deferred->complete($result);
    }

    public function fail(Throwable $error): void
    {
        $this->deferred->error($error);
    }
}
