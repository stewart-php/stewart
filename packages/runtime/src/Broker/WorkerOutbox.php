<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\State\StateChange;
use Stewart\Runtime\Control\Protocol\Status\OutboxStatus;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\OutboxDelivery;
use Stewart\Runtime\Ipc\Message\StateChangeBatch;
use Stewart\Runtime\Ipc\Wire\Collection\EncodedStateChangeCollection;
use Stewart\Runtime\Ipc\Wire\EncodedStateChange;
use Stewart\Runtime\Ipc\Wire\StateChangesFragment;

final class WorkerOutbox
{
    /** @var array<int, BrokerMessage|EncodedStateChange> */
    private array $queue = [];

    private int $nextSequence = 0;

    // Per entity: the first queued change and one merged tail; a segment boundary resets both.
    /** @var array<string, int> */
    private array $firstStateChanges = [];

    /** @var array<string, int> */
    private array $lastStateChanges = [];

    /** @var array<class-string<BrokerMessage>, int> */
    private array $latestOnlySequences = [];

    /** @var array<int, true> in queue order */
    private array $droppable = [];

    public private(set) int $dropped = 0;

    public private(set) int $coalescedStateChanges = 0;

    private int $batchesSent = 0;

    private int $largestBatch = 0;

    public function __construct(private readonly OutboxLimits $limits) {}

    public function enqueue(BrokerMessage $message): void
    {
        match ($message->getOutboxDelivery()) {
            OutboxDelivery::Droppable => $this->pushDroppable($message),
            OutboxDelivery::StateSegmentBoundary => $this->pushSegmentBoundary($message),
            OutboxDelivery::LatestOnly => $this->replaceOrAppendLatest($message),
            OutboxDelivery::Plain => $this->appendToQueue($message),
        };
    }

    public function pushStateChange(EncodedStateChange $change): void
    {
        $entityId = $change->change->entityId->value;

        if (!isset($this->firstStateChanges[$entityId])) {
            $this->firstStateChanges[$entityId] = $this->appendToQueue($change);

            return;
        }

        $lastSequence = $this->lastStateChanges[$entityId] ?? null;
        $last = $lastSequence === null ? null : $this->queue[$lastSequence];

        if ($lastSequence === null || !$last instanceof EncodedStateChange) {
            $this->lastStateChanges[$entityId] = $this->appendToQueue($change);

            return;
        }

        // Re-appended at the end, so the merged change never overtakes a later event.
        unset($this->queue[$lastSequence]);

        $this->lastStateChanges[$entityId] = $this->appendToQueue(new EncodedStateChange(new StateChange(
            entityId: $change->change->entityId,
            from: $last->change->from,
            to: $change->change->to,
            firedAt: $change->change->firedAt,
            context: $change->change->context,
            origin: $change->change->origin,
        )));

        ++$this->coalescedStateChanges;
    }

    public function takeNext(): ?BrokerMessage
    {
        $entry = $this->shiftEntry();

        if (!$entry instanceof EncodedStateChange) {
            return $entry;
        }

        $batch = [$entry];

        while (\count($batch) < $this->limits->stateBatch && $this->headIsStateChange()) {
            $next = $this->shiftEntry();
            \assert($next instanceof EncodedStateChange);
            $batch[] = $next;
        }

        ++$this->batchesSent;
        $this->largestBatch = max($this->largestBatch, \count($batch));

        return new StateChangeBatch(StateChangesFragment::fromEncodedChanges(EncodedStateChangeCollection::fromChanges($batch)));
    }

    public function clear(): void
    {
        $this->queue = [];
        $this->firstStateChanges = [];
        $this->lastStateChanges = [];
        $this->droppable = [];
        $this->latestOnlySequences = [];
    }

    public function buildStatus(): OutboxStatus
    {
        return new OutboxStatus(
            queued: \count($this->queue),
            dropped: $this->dropped,
            coalescedStateChanges: $this->coalescedStateChanges,
            stateBatchesSent: $this->batchesSent,
            largestStateBatch: $this->largestBatch,
        );
    }

    private function shiftEntry(): BrokerMessage|EncodedStateChange|null
    {
        $sequence = array_key_first($this->queue);

        if ($sequence === null) {
            return null;
        }

        $entry = $this->queue[$sequence];
        unset($this->queue[$sequence]);

        if ($entry instanceof EncodedStateChange) {
            $this->forgetStateChange($entry->change->entityId->value, $sequence);

            return $entry;
        }

        unset($this->droppable[$sequence]);

        if (($this->latestOnlySequences[$entry::class] ?? null) === $sequence) {
            unset($this->latestOnlySequences[$entry::class]);
        }

        return $entry;
    }

    private function forgetStateChange(string $entityId, int $sequence): void
    {
        if (($this->firstStateChanges[$entityId] ?? null) !== $sequence) {
            return;
        }

        unset($this->firstStateChanges[$entityId]);

        if (isset($this->lastStateChanges[$entityId])) {
            $this->firstStateChanges[$entityId] = $this->lastStateChanges[$entityId];
            unset($this->lastStateChanges[$entityId]);
        }
    }

    private function headIsStateChange(): bool
    {
        $sequence = array_key_first($this->queue);

        return $sequence !== null && $this->queue[$sequence] instanceof EncodedStateChange;
    }

    private function pushDroppable(BrokerMessage $message): void
    {
        $this->droppable[$this->appendToQueue($message)] = true;

        if (\count($this->droppable) <= $this->limits->eventBuffer) {
            return;
        }

        $oldest = array_key_first($this->droppable);
        unset($this->droppable[$oldest], $this->queue[$oldest]);
        ++$this->dropped;
    }

    private function replaceOrAppendLatest(BrokerMessage $message): void
    {
        $queuedSequence = $this->latestOnlySequences[$message::class] ?? null;

        if ($queuedSequence !== null) {
            $this->queue[$queuedSequence] = $message;

            return;
        }

        $this->latestOnlySequences[$message::class] = $this->appendToQueue($message);
    }

    private function pushSegmentBoundary(BrokerMessage $message): void
    {
        $this->appendToQueue($message);
        $this->firstStateChanges = [];
        $this->lastStateChanges = [];
    }

    private function appendToQueue(BrokerMessage|EncodedStateChange $entry): int
    {
        $sequence = $this->nextSequence++;
        $this->queue[$sequence] = $entry;

        return $sequence;
    }
}
