<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Time\Instant;

#[IpcMessage(tag: 'ha_connection_lost')]
final readonly class HaConnectionLost implements BrokerMessage
{
    public function __construct(
        public Instant $lostAt,
        public string $reason,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
