<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Runtime\Control\Protocol\Status\OutboxStatus;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\OutboxDelivery;
use Stewart\Runtime\Ipc\Message\StateUpdateBatch;
use Stewart\Runtime\Ipc\Wire\Collection\EncodedStateUpdateCollection;
use Stewart\Runtime\Ipc\Wire\EncodedStateUpdate;
use Stewart\Runtime\Ipc\Wire\StateUpdatesFragment;

final class WorkerOutbox
{
    /** @var array<int, BrokerMessage|EncodedStateUpdate> */
    private array $queue = [];

    private int $nextSequence = 0;

    // Per entity: the first queued update and one merged tail; a segment boundary resets both.
    /** @var array<string, int> */
    private array $firstStateUpdates = [];

    /** @var array<string, int> */
    private array $lastStateUpdates = [];

    /** @var array<class-string<BrokerMessage>, int> */
    private array $latestOnlySequences = [];

    /** @var array<int, true> in queue order */
    private array $droppable = [];

    private int $queuedStateUpdates = 0;

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

    public function pushStateUpdate(EncodedStateUpdate $update): void
    {
        $entityId = $update->update->entityId->value;

        if (!isset($this->firstStateUpdates[$entityId])) {
            $this->firstStateUpdates[$entityId] = $this->appendStateUpdate($update);

            return;
        }

        $lastSequence = $this->lastStateUpdates[$entityId] ?? null;

        // Only a backlog beyond the next batch merges; re-appending keeps the merged update behind later events.
        if ($lastSequence !== null && $this->queuedStateUpdates >= $this->limits->stateBatch) {
            unset($this->queue[$lastSequence]);
            --$this->queuedStateUpdates;
            ++$this->coalescedStateChanges;
        }

        $this->lastStateUpdates[$entityId] = $this->appendStateUpdate($update);
    }

    public function takeNext(): ?BrokerMessage
    {
        $entry = $this->shiftEntry();

        if (!$entry instanceof EncodedStateUpdate) {
            return $entry;
        }

        $batch = [$entry];

        while (\count($batch) < $this->limits->stateBatch && $this->headIsStateUpdate()) {
            $next = $this->shiftEntry();
            \assert($next instanceof EncodedStateUpdate);
            $batch[] = $next;
        }

        ++$this->batchesSent;
        $this->largestBatch = max($this->largestBatch, \count($batch));

        return new StateUpdateBatch(StateUpdatesFragment::fromEncodedUpdates(EncodedStateUpdateCollection::fromList($batch)));
    }

    public function clear(): void
    {
        $this->queue = [];
        $this->firstStateUpdates = [];
        $this->lastStateUpdates = [];
        $this->droppable = [];
        $this->latestOnlySequences = [];
        $this->queuedStateUpdates = 0;
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

    private function shiftEntry(): BrokerMessage|EncodedStateUpdate|null
    {
        $sequence = array_key_first($this->queue);

        if ($sequence === null) {
            return null;
        }

        $entry = $this->queue[$sequence];
        unset($this->queue[$sequence]);

        if ($entry instanceof EncodedStateUpdate) {
            --$this->queuedStateUpdates;
            $this->forgetStateUpdate($entry->update->entityId->value, $sequence);

            return $entry;
        }

        unset($this->droppable[$sequence]);

        if (($this->latestOnlySequences[$entry::class] ?? null) === $sequence) {
            unset($this->latestOnlySequences[$entry::class]);
        }

        return $entry;
    }

    private function forgetStateUpdate(string $entityId, int $sequence): void
    {
        if (($this->firstStateUpdates[$entityId] ?? null) !== $sequence) {
            return;
        }

        unset($this->firstStateUpdates[$entityId]);

        if (isset($this->lastStateUpdates[$entityId])) {
            $this->firstStateUpdates[$entityId] = $this->lastStateUpdates[$entityId];
            unset($this->lastStateUpdates[$entityId]);
        }
    }

    private function headIsStateUpdate(): bool
    {
        $sequence = array_key_first($this->queue);

        return $sequence !== null && $this->queue[$sequence] instanceof EncodedStateUpdate;
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
        $this->firstStateUpdates = [];
        $this->lastStateUpdates = [];
    }

    private function appendStateUpdate(EncodedStateUpdate $update): int
    {
        ++$this->queuedStateUpdates;

        return $this->appendToQueue($update);
    }

    private function appendToQueue(BrokerMessage|EncodedStateUpdate $entry): int
    {
        $sequence = $this->nextSequence++;
        $this->queue[$sequence] = $entry;

        return $sequence;
    }
}
