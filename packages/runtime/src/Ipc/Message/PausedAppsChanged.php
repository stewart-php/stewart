<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Runtime\Ipc\Wire\AppIdsFragment;

#[IpcMessage(tag: 'paused_apps_changed')]
final readonly class PausedAppsChanged implements BrokerMessage
{
    public function __construct(public AppIdsFragment $pausedAppIds) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
