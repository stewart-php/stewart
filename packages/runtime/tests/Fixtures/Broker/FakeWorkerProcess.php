<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use Amp\DeferredCancellation;
use RuntimeException;
use Stewart\Runtime\Broker\WorkerProcess;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Testing\Async\Latch;
use Throwable;

final class FakeWorkerProcess implements WorkerProcess
{
    public bool $closed = false;

    public int $joins = 0;

    public readonly FakeWorkerTransport $channel;

    private readonly DeferredCancellation $kill;

    private string $exitSummary = 'fake worker stopped';

    private ?Throwable $exitFailure = null;

    public function __construct(
        private readonly ?Latch $joinLatch = null,
        private readonly int $pid = 4242,
        private readonly string $stdout = '',
    ) {
        $this->channel = new FakeWorkerTransport();
        $this->kill = new DeferredCancellation();
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function transport(): Transport
    {
        return $this->channel;
    }

    public function stdout(): ReadableStream
    {
        return new ReadableBuffer($this->stdout);
    }

    public function stderr(): ReadableStream
    {
        return new ReadableBuffer();
    }

    // Like amphp's process join: no deadline, only an exit or a kill ends the wait.
    public function join(): string
    {
        ++$this->joins;
        $this->joinLatch?->waitUntilOpen($this->kill->getCancellation());

        if ($this->closed) {
            throw new RuntimeException('Context exited with code 137');
        }

        return $this->exitFailure === null ? $this->exitSummary : throw $this->exitFailure;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->kill->cancel();
        $this->channel->hangUp();
    }

    public function crash(): void
    {
        $this->channel->hangUp();
    }

    public function crashWithSummary(string $summary): void
    {
        $this->exitSummary = $summary;
        $this->crash();
    }

    public function crashWithFailure(Throwable $failure): void
    {
        $this->exitFailure = $failure;
        $this->crash();
    }
}
