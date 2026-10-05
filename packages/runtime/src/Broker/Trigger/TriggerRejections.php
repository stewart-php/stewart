<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Trigger;

use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Ipc\Message\SubscriptionAck;
use Stewart\Runtime\Model\SubscriptionKind;

final readonly class TriggerRejections
{
    public function __construct(
        private SubscriptionRegistry $registry,
        private WorkerSlotRegistry $slots,
    ) {}

    public function refuseSubscribers(TriggerSpec $spec, string $reason): void
    {
        $routes = $this->registry->findRoutes(SubscriptionKind::Trigger, $spec->getSharingKey());

        foreach ($routes->listWorkerIds() as $workerId) {
            $handle = $this->slots->findHandleForWorker($workerId);

            foreach ($routes->listSubscriptionIdsForWorker($workerId) as $subscriptionId) {
                $handle?->send(new SubscriptionAck($subscriptionId, false, $reason));
                $this->registry->remove($subscriptionId);
            }
        }
    }
}
