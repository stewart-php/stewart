<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Runtime\Ipc\Wire\RegistryFragment;

#[IpcMessage(tag: 'registry_snapshot')]
final readonly class RegistrySnapshot implements BrokerMessage
{
    public function __construct(
        public RegistryFragment $registry,
        public int $revision,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::LatestOnly;
    }
}
