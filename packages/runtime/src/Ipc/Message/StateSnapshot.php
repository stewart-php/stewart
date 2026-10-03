<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;

#[IpcMessage(tag: 'state_snapshot')]
final readonly class StateSnapshot implements BrokerMessage
{
    public function __construct(
        public EntityStatesFragment $states,
        public int $revision,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::StateSegmentBoundary;
    }
}
