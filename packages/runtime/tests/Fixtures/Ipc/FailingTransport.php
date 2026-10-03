<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Ipc;

use Stewart\Runtime\Ipc\Transport;
use Throwable;

final class FailingTransport implements Transport
{
    public int $attempts = 0;

    public function __construct(private readonly Throwable $failure) {}

    public function send(object $message): void
    {
        ++$this->attempts;

        throw $this->failure;
    }

    public function receive(): ?object
    {
        return null;
    }

    public function close(): void {}
}
