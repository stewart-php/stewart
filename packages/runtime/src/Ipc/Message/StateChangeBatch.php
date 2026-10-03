<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Runtime\Ipc\Wire\StateChangesFragment;

#[IpcMessage(tag: 'state_changes')]
final readonly class StateChangeBatch implements BrokerMessage
{
    public function __construct(public StateChangesFragment $changes) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
