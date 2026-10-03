<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc;

use Stewart\Runtime\Exception\TransportException;

interface Transport
{
    /** @throws TransportException */
    public function send(object $message): void;

    /** @throws TransportException */
    public function receive(): ?object;

    public function close(): void;
}
