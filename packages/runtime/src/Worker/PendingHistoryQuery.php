<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Amp\DeferredFuture;
use Amp\Future;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Runtime\Model\CorrelationId;
use Throwable;

final readonly class PendingHistoryQuery
{
    /** @var DeferredFuture<EntityStateHistory> */
    private DeferredFuture $deferred;

    public function __construct(
        public CorrelationId $correlationId,
        public EntityId $entityId,
        public HistoryWindow $window,
    ) {
        /** @var DeferredFuture<EntityStateHistory> $deferred */
        $deferred = new DeferredFuture();
        $this->deferred = $deferred;
    }

    /** @return Future<EntityStateHistory> */
    public function getFuture(): Future
    {
        return $this->deferred->getFuture();
    }

    public function complete(EntityStateHistory $history): void
    {
        $this->deferred->complete($history);
    }

    public function fail(Throwable $error): void
    {
        $this->deferred->error($error);
    }
}
