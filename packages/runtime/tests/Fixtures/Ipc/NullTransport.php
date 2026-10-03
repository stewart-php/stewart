<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Ipc;

use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Transport;

final class NullTransport implements Transport
{
    /** @var list<object> */
    public array $sent = [];

    private bool $closed = false;

    public function send(object $message): void
    {
        if ($this->closed) {
            throw TransportException::closed();
        }

        $this->sent[] = $message;
    }

    public function receive(): ?object
    {
        return null;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
