<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Time\Duration;

#[IpcMessage(tag: 'shutdown')]
final readonly class Shutdown implements BrokerMessage
{
    public function __construct(
        public string $reason,
        public Duration $grace,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
