<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Closure;
use SplQueue;
use Stewart\Contracts\State\StateChange;
use Stewart\Runtime\Scope\ScopeState;
use Throwable;

use function Amp\async;

final class SubscriptionQueue
{
    /** @var SplQueue<QueuedWork> */
    private SplQueue $pending;

    private int $pendingEvents = 0;

    private bool $draining = false;

    private bool $cancelled = false;

    private int $dropped = 0;

    public function __construct(
        public readonly RegisteredSubscription $subscription,
        private readonly int $limit,
        private readonly DispatchListener $listener,
        private readonly ScopeState $scopeState,
    ) {
        $this->pending = new SplQueue();
    }

    /** @param ?Closure(QueueDiscardReason): void $onDiscarded */
    public function push(object $event, ?Closure $onDiscarded = null): void
    {
        if ($this->cancelled) {
            $onDiscarded?->__invoke(QueueDiscardReason::Cancelled);

            return;
        }

        if ($this->scopeState->isPaused()) {
            $this->listener->eventSuppressed($this->subscription);
            $onDiscarded?->__invoke(QueueDiscardReason::Paused);

            return;
        }

        if ($this->pendingEvents >= $this->limit) {
            $event = $this->dropOnOverflow($event);
        }

        ++$this->pendingEvents;

        $this->enqueue(QueuedWork::forDroppableEvent($event, $this->subscription->handler, $onDiscarded));
    }

    /** @param Closure(): void $emission */
    public function emit(Closure $emission): void
    {
        if ($this->cancelled) {
            return;
        }

        if ($this->scopeState->isPaused()) {
            $this->listener->eventSuppressed($this->subscription);

            return;
        }

        $this->enqueue(QueuedWork::forStreamEmission($emission));
    }

    public function startDrainingIfPending(): void
    {
        if (!$this->cancelled && !$this->pending->isEmpty()) {
            $this->startDraining();
        }
    }

    public function discardPendingWork(): void
    {
        if ($this->cancelled) {
            return;
        }

        $discarded = $this->pending;
        $this->pending = new SplQueue();
        $this->pendingEvents = 0;

        foreach ($discarded as $work) {
            $this->listener->eventSuppressed($this->subscription);
            $work->discard(QueueDiscardReason::Paused);
        }
    }

    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        $discarded = $this->pending;
        $this->pending = new SplQueue();
        $this->pendingEvents = 0;

        foreach ($discarded as $work) {
            $work->discard(QueueDiscardReason::Cancelled);
        }

        try {
            $this->subscription->subscriptionScope->close();
        } catch (Throwable $e) {
            $this->listener->handlerFailed($this->subscription, $e);
        }
    }

    private function dropOnOverflow(object $incoming): object
    {
        if ($incoming instanceof StateChange) {
            $superseded = $this->findOldestSupersededIndex($incoming);

            if ($superseded !== null) {
                return $this->dropSupersededStateChange($superseded, $incoming);
            }
        }

        $this->dropOldestEvent();

        return $incoming;
    }

    private function findOldestSupersededIndex(StateChange $incoming): ?int
    {
        $firstIndexByEntity = [];
        $supersededIndexes = [];

        foreach ($this->pending as $index => $work) {
            if (!$work->event instanceof StateChange) {
                continue;
            }

            $entityId = $work->event->entityId->value;

            if (isset($firstIndexByEntity[$entityId])) {
                $supersededIndexes[] = $firstIndexByEntity[$entityId];

                continue;
            }

            $firstIndexByEntity[$entityId] = $index;
        }

        if (isset($firstIndexByEntity[$incoming->entityId->value])) {
            $supersededIndexes[] = $firstIndexByEntity[$incoming->entityId->value];
        }

        return $supersededIndexes === [] ? null : min($supersededIndexes);
    }

    private function dropSupersededStateChange(int $index, StateChange $incoming): StateChange
    {
        $superseded = $this->pending->offsetGet($index);
        \assert($superseded->event instanceof StateChange);

        $this->pending->offsetUnset($index);
        $this->recordOverflowDrop($superseded);

        $entityId = $superseded->event->entityId->value;
        $from = $superseded->event->from;

        for ($next = $index; $next < $this->pending->count(); ++$next) {
            $work = $this->pending->offsetGet($next);

            if ($work->event instanceof StateChange && $work->event->entityId->value === $entityId) {
                $this->pending->offsetSet($next, $work->withEvent($work->event->withFrom($from)));

                return $incoming;
            }
        }

        return $incoming->withFrom($from);
    }

    private function dropOldestEvent(): void
    {
        foreach ($this->pending as $index => $work) {
            if (!$work->isDroppable()) {
                continue;
            }

            $this->pending->offsetUnset($index);
            $this->recordOverflowDrop($work);

            return;
        }
    }

    private function recordOverflowDrop(QueuedWork $work): void
    {
        --$this->pendingEvents;
        ++$this->dropped;

        $this->listener->eventDropped($this->subscription, $this->dropped);
        $work->discard(QueueDiscardReason::Overflow);
    }

    private function enqueue(QueuedWork $work): void
    {
        $this->pending->enqueue($work);

        if ($this->scopeState->isLive()) {
            $this->startDraining();
        }
    }

    private function startDraining(): void
    {
        if ($this->draining) {
            return;
        }

        $this->draining = true;

        async($this->drain(...))->ignore();
    }

    private function drain(): void
    {
        try {
            while (!$this->cancelled && !$this->scopeState->isPaused() && !$this->pending->isEmpty()) {
                $work = $this->pending->dequeue();

                if ($work->isDroppable()) {
                    --$this->pendingEvents;
                }

                $this->runQueuedWork($work);
            }
        } finally {
            $this->draining = false;
        }
    }

    private function runQueuedWork(QueuedWork $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            $this->listener->handlerFailed($this->subscription, $e);

            return;
        }

        if ($work->isDroppable()) {
            $this->listener->eventDelivered($this->subscription);
        }
    }
}
