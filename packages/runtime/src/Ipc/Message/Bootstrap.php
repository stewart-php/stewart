<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Runtime\Ipc\StoreSettings;
use Stewart\Runtime\Ipc\Wire\AppIdsFragment;
use Stewart\Runtime\Ipc\Wire\WorkerAppsFragment;
use Stewart\Runtime\Ipc\WorkerSettings;
use Stewart\Runtime\Model\WorkerId;

#[IpcMessage(tag: 'bootstrap')]
final readonly class Bootstrap implements BrokerMessage
{
    public function __construct(
        public int $protocol,
        public WorkerId $workerId,
        public string $timeZone,
        public ?GeoLocation $location,
        public WorkerAppsFragment $apps,
        public WorkerSettings $settings,
        public ?StoreSettings $store,
        public AppIdsFragment $knownAppIds,
        public ?string $haUserId,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
