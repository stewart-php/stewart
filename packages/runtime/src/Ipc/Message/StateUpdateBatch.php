<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Runtime\Ipc\Wire\StateUpdatesFragment;

#[IpcMessage(tag: 'state_updates')]
final readonly class StateUpdateBatch implements BrokerMessage
{
    public function __construct(public StateUpdatesFragment $updates) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
