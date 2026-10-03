<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;

#[IpcMessage(tag: 'state_resynced')]
final readonly class StateResynced implements BrokerMessage
{
    public function __construct(
        public EntityStatesFragment $states,
        public int $revision,
        public Duration $outage,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::StateSegmentBoundary;
    }
}
