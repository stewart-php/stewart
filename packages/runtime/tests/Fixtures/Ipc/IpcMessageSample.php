<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Ipc;

final readonly class IpcMessageSample
{
    public function __construct(
        public object $sent,
        public object $received,
    ) {}

    public static function createRoundTrip(object $message): self
    {
        return new self($message, $message);
    }
}
