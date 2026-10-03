<?php

declare(strict_types=1);

namespace Stewart\Client\Connection;

use Amp\Future;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\DisposedException;
use Amp\Pipeline\Queue;
use Closure;
use Fiber;
use Psr\Log\LoggerInterface;
use Stewart\Client\Exception\HaClientException;
use Throwable;

use function Amp\async;

/** @internal */
final class EventDelivery
{
    /** @var Queue<Closure(): void> */
    private readonly Queue $queue;

    /** @var ConcurrentIterator<Closure(): void> */
    private readonly ConcurrentIterator $deliveries;

    /** @var Fiber<mixed, mixed, mixed, mixed>|null */
    private ?Fiber $deliverer = null;

    private bool $started = false;

    private bool $closed = false;

    private int $queued = 0;

    public function __construct(
        private readonly int $limit,
        private readonly LoggerInterface $logger,
    ) {
        $this->queue = new Queue();
        $this->deliveries = $this->queue->iterate();
    }

    /**
     * @param Closure(array<string, mixed>): void $handler
     * @param array<string, mixed> $payload
     */
    public function enqueue(Closure $handler, array $payload): EnqueueOutcome
    {
        if ($this->closed) {
            return EnqueueOutcome::Closed;
        }

        if ($this->queued >= $this->limit) {
            return EnqueueOutcome::Full;
        }

        $this->enqueueDelivery(static fn() => $handler($payload))->ignore();

        return EnqueueOutcome::Accepted;
    }

    /** @throws HaClientException */
    public function flush(): void
    {
        if ($this->deliverer !== null && $this->deliverer === Fiber::getCurrent()) {
            throw HaClientException::eventQueueReentered();
        }

        if ($this->closed) {
            throw self::createDroppedException();
        }

        try {
            // Resolves when the consumer takes this marker, which is after every earlier event.
            $this->enqueueDelivery(static function (): void {})->await();
        } catch (DisposedException) {
            throw self::createDroppedException();
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->queued = 0;
        $this->deliveries->dispose();
    }

    /**
     * @param Closure(): void $delivery
     * @return Future<null>
     */
    private function enqueueDelivery(Closure $delivery): Future
    {
        ++$this->queued;

        if (!$this->started) {
            $this->started = true;
            async($this->deliverQueuedEvents(...))->ignore();
        }

        return $this->queue->pushAsync($delivery);
    }

    private function deliverQueuedEvents(): void
    {
        $this->deliverer = Fiber::getCurrent();

        try {
            while ($this->deliveries->continue()) {
                // continue() still yields queued values after disposal.
                if ($this->closed) {
                    return;
                }

                --$this->queued;

                try {
                    ($this->deliveries->getValue())();
                } catch (Throwable $e) {
                    $this->logger->error('A Home Assistant event handler failed', ['exception' => $e]);
                }
            }
        } catch (DisposedException) {
        } finally {
            $this->deliverer = null;
        }
    }

    private static function createDroppedException(): HaClientException
    {
        return HaClientException::connectionDropped('closed while events were being flushed');
    }
}
