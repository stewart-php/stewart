<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Amp\ByteStream\ReadableStream;
use Amp\Parallel\Context\ProcessContext;
use Stewart\Runtime\Ipc\ChannelTransport;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Ipc\Wire\IpcCodec;

final class ProcessContextWorker implements WorkerProcess
{
    private readonly Transport $transport;

    /** @param ProcessContext<string, string, string> $context */
    public function __construct(private readonly ProcessContext $context, IpcCodec $ipcCodec)
    {
        $this->transport = new ChannelTransport($context, $ipcCodec);
    }

    public function getPid(): int
    {
        return $this->context->getPid();
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    public function stdout(): ReadableStream
    {
        return $this->context->getStdout();
    }

    public function stderr(): ReadableStream
    {
        return $this->context->getStderr();
    }

    public function join(): string
    {
        $summary = $this->context->join();

        return \is_string($summary) ? $summary : '';
    }

    public function close(): void
    {
        $this->context->close();
    }
}
