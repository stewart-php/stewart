<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Time\Instant;

#[IpcMessage(tag: 'ping')]
final readonly class Ping implements BrokerMessage
{
    public function __construct(
        public int $nonce,
        public Instant $sentAt,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::LatestOnly;
    }
}
