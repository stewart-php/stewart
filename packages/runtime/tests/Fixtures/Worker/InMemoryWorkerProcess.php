<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Worker;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use Amp\Future;
use Stewart\Runtime\Broker\WorkerProcess;
use Stewart\Runtime\Ipc\Transport;

final readonly class InMemoryWorkerProcess implements WorkerProcess
{
    /** @param Future<string> $session */
    public function __construct(
        private Transport $transport,
        private Future $session,
    ) {}

    public function getPid(): int
    {
        return getmypid() ?: 0;
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    public function stdout(): ReadableStream
    {
        return new ReadableBuffer();
    }

    public function stderr(): ReadableStream
    {
        return new ReadableBuffer();
    }

    public function join(): string
    {
        return $this->session->await();
    }

    // A fiber cannot be killed; closing the transport makes the session stop.
    public function close(): void
    {
        $this->transport->close();
    }
}
