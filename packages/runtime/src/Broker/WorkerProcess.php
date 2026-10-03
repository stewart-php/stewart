<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Amp\ByteStream\ReadableStream;
use Stewart\Runtime\Ipc\Transport;

interface WorkerProcess
{
    public function getPid(): int;

    public function transport(): Transport;

    public function stdout(): ReadableStream;

    public function stderr(): ReadableStream;

    public function join(): string;

    public function close(): void;
}
