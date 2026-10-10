<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Amp\ByteStream;
use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\Future;
use LogicException;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Control\Protocol\Status\OutboxStatus;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Ipc\Wire\EncodedStateChange;
use Stewart\Runtime\Lifecycle\WorkerHandleState;
use Stewart\Runtime\Logging\EveryNthOccurrence;
use Stewart\Runtime\Model\WorkerId;
use Throwable;

use function Amp\async;

final class WorkerHandle
{
    private readonly WorkerOutbox $outbox;

    private readonly EveryNthOccurrence $dropReports;

    private bool $flushing = false;

    private WorkerHandleState $state = WorkerHandleState::Starting;

    public private(set) ?Pong $lastPong = null;

    public private(set) ?Instant $lastPongAt = null;

    private int $lastAnsweredNonce;

    private int $reportedDrops = 0;

    public function __construct(
        public readonly WorkerId $id,
        private readonly WorkerProcess $process,
        public readonly WorkerSlot $slot,
        private readonly LoggerInterface $logger,
        OutboxLimits $outboxLimits,
        int $probesSentBefore = 0,
    ) {
        $this->outbox = new WorkerOutbox($outboxLimits);
        $this->dropReports = EveryNthOccurrence::forRepeatedWarnings();
        // Probes sent before the spawn do not count as missed.
        $this->lastAnsweredNonce = $probesSentBefore;
    }

    public function getPid(): int
    {
        return $this->process->getPid();
    }

    public function getAppIds(): AppIdCollection
    {
        return $this->slot->listAppIds();
    }

    public function getTransport(): Transport
    {
        return $this->process->transport();
    }

    public function relayOutput(): void
    {
        $this->relayLines($this->process->stdout(), 'stdout');
        $this->relayLines($this->process->stderr(), 'stderr');
    }

    public function isReady(): bool
    {
        return $this->state === WorkerHandleState::Ready;
    }

    public function isStarting(): bool
    {
        return $this->state === WorkerHandleState::Starting;
    }

    public function isStopping(): bool
    {
        return $this->state === WorkerHandleState::Stopping;
    }

    public function isTerminated(): bool
    {
        return $this->state === WorkerHandleState::Terminated;
    }

    public function send(BrokerMessage $message): void
    {
        if (!$this->acceptsMessages()) {
            return;
        }

        $this->outbox->enqueue($message);
        $this->reportDrops();
        $this->flush();
    }

    public function sendStateChange(EncodedStateChange $change): void
    {
        if (!$this->acceptsMessages()) {
            return;
        }

        $this->outbox->pushStateChange($change);
        $this->flush();
    }

    public function sendShutdown(Shutdown $shutdown): void
    {
        if (!$this->acceptsMessages()) {
            return;
        }

        $this->outbox->enqueue($shutdown);
        $this->moveTo(WorkerHandleState::Stopping);
        $this->flush();
    }

    public function markReady(): void
    {
        $this->moveTo(WorkerHandleState::Ready);
    }

    public function recordPong(Pong $pong, Instant $at): void
    {
        $this->lastAnsweredNonce = max($this->lastAnsweredNonce, $pong->nonce);
        $this->lastPong = $pong;
        $this->lastPongAt = $at;
    }

    public function countMissedProbes(int $nextNonce): int
    {
        return max(0, $nextNonce - $this->lastAnsweredNonce - 1);
    }

    public function getOutboxStatus(): OutboxStatus
    {
        return $this->outbox->buildStatus();
    }

    // amphp's join waits for the process exit without a deadline; terminate() ends the abandoned wait.
    public function awaitExit(Cancellation $deadline): string
    {
        /** @var Future<string> $exit */
        $exit = async(fn(): string => $this->process->join());
        $exit->ignore();

        return $exit->await($deadline);
    }

    public function terminate(): void
    {
        if ($this->isTerminated()) {
            return;
        }

        $this->moveTo(WorkerHandleState::Terminated);
        $this->outbox->clear();
        $this->process->close();
    }

    private function acceptsMessages(): bool
    {
        return $this->state === WorkerHandleState::Starting || $this->state === WorkerHandleState::Ready;
    }

    private function moveTo(WorkerHandleState $state): void
    {
        if (!$this->state->canEnter($state)) {
            throw new LogicException(\sprintf('Worker %d handle cannot move from %s to %s.', $this->id->value, $this->state->name, $state->name));
        }

        $this->state = $state;
    }

    private function relayLines(ReadableStream $stream, string $name): void
    {
        async(function () use ($stream, $name): void {
            try {
                foreach (ByteStream\splitLines($stream) as $line) {
                    if ($line !== '') {
                        $this->logger->notice($line, ['worker' => $this->id->value, 'stream' => $name]);
                    }
                }
            } catch (Throwable $e) {
                $this->logger->warning('Lost a worker output stream', ['worker' => $this->id->value, 'stream' => $name, 'exception' => $e]);
            }
        })->ignore();
    }

    private function handleFailedSend(Throwable $failure): void
    {
        if ($this->isTerminated()) {
            return;
        }

        // A stopping worker closes its channel before exiting; the shutdown deadline still bounds the wait.
        if ($this->isStopping()) {
            $this->logger->debug('Stopped sending to a stopping worker', ['worker' => $this->id->value, 'reason' => $failure->getMessage()]);

            return;
        }

        $this->logger->warning('Failed sending to worker; terminating it', ['worker' => $this->id->value, 'exception' => $failure]);
        $this->terminate();
    }

    private function reportDrops(): void
    {
        $dropped = $this->outbox->dropped;

        if ($dropped === $this->reportedDrops) {
            return;
        }

        $this->reportedDrops = $dropped;

        if ($this->dropReports->includesOccurrence($dropped)) {
            $this->logger->warning('Worker is falling behind; dropping its oldest events and topic messages', [
                'worker' => $this->id->value,
                'dropped' => $dropped,
                'coalesced_state_changes' => $this->outbox->coalescedStateChanges,
            ]);
        }
    }

    private function flush(): void
    {
        if ($this->flushing) {
            return;
        }

        $this->flushing = true;

        async(function (): void {
            try {
                while (($message = $this->outbox->takeNext()) !== null) {
                    $this->getTransport()->send($message);
                }
            } catch (Throwable $e) {
                $this->handleFailedSend($e);
            } finally {
                $this->flushing = false;
            }
        })->ignore();
    }
}
