<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Closure;
use SplQueue;
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
        public private(set) bool $live = true,
    ) {
        $this->pending = new SplQueue();
    }

    public function push(object $event): void
    {
        if ($this->cancelled) {
            return;
        }

        if ($this->pendingEvents >= $this->limit) {
            $this->dropOldestEvent();
        }

        $handler = $this->subscription->handler;

        ++$this->pendingEvents;

        $this->enqueue(QueuedWork::forDroppableEvent(static function () use ($handler, $event): void {
            $handler($event);
        }));
    }

    /** @param Closure(): void $emission */
    public function emit(Closure $emission): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->enqueue(QueuedWork::forStreamEmission($emission));
    }

    public function activate(): void
    {
        if ($this->cancelled || $this->live) {
            return;
        }

        $this->live = true;

        if (!$this->pending->isEmpty()) {
            $this->startDraining();
        }
    }

    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        $this->pending = new SplQueue();
        $this->pendingEvents = 0;

        try {
            $this->subscription->subscriptionScope->close();
        } catch (Throwable $e) {
            $this->listener->handlerFailed($this->subscription, $e);
        }
    }

    private function dropOldestEvent(): void
    {
        foreach ($this->pending as $index => $work) {
            if (!$work->droppable) {
                continue;
            }

            $this->pending->offsetUnset($index);
            --$this->pendingEvents;
            ++$this->dropped;

            $this->listener->eventDropped($this->subscription, $this->dropped);

            return;
        }
    }

    private function enqueue(QueuedWork $work): void
    {
        $this->pending->enqueue($work);

        if ($this->live) {
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
            while (!$this->cancelled && !$this->pending->isEmpty()) {
                $work = $this->pending->dequeue();

                if ($work->droppable) {
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

        if ($work->droppable) {
            $this->listener->eventDelivered($this->subscription);
        }
    }
}
