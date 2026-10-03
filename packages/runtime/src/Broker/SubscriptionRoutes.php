<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Runtime\Model\Collection\SubscriptionIdCollection;
use Stewart\Runtime\Model\Collection\WorkerIdCollection;
use Stewart\Runtime\Model\WorkerId;

final readonly class SubscriptionRoutes
{
    /** @param array<int, SubscriptionIdCollection> $subscriptionIdsByWorker */
    public function __construct(private array $subscriptionIdsByWorker = []) {}

    public function listSubscriptionIdsForWorker(WorkerId $workerId): SubscriptionIdCollection
    {
        return $this->subscriptionIdsByWorker[$workerId->value] ?? SubscriptionIdCollection::empty();
    }

    public function listWorkerIds(): WorkerIdCollection
    {
        return WorkerIdCollection::fromIds(array_map(WorkerId::fromInt(...), array_keys($this->subscriptionIdsByWorker)));
    }

    public function isEmpty(): bool
    {
        return $this->subscriptionIdsByWorker === [];
    }
}
