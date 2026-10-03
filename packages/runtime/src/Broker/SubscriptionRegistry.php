<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Runtime\Broker\Collection\BrokerSubscriptionCollection;
use Stewart\Runtime\Dispatch\SelectorIndex;
use Stewart\Runtime\Model\Collection\SubscriptionIdCollection;
use Stewart\Runtime\Model\RoutingStats;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Model\WorkerId;

final class SubscriptionRegistry
{
    /** @var array<string, BrokerSubscription> */
    private array $subscriptions = [];

    private readonly SelectorIndex $index;

    public function __construct()
    {
        $this->index = new SelectorIndex();
    }

    public function add(BrokerSubscription $subscription): void
    {
        $this->subscriptions[$subscription->subscriptionId->value] = $subscription;
        $this->index->add($subscription->subscriptionId, $subscription->kind, $subscription->selector);
    }

    public function remove(SubscriptionId $subscriptionId, ?WorkerId $ownedBy = null): void
    {
        $subscription = $this->subscriptions[$subscriptionId->value] ?? null;

        if ($subscription === null || ($ownedBy !== null && !$subscription->workerId->equals($ownedBy))) {
            return;
        }

        unset($this->subscriptions[$subscriptionId->value]);
        $this->index->remove($subscriptionId);
    }

    public function removeWorkerSubscriptions(WorkerId $workerId): void
    {
        foreach ($this->subscriptions as $subscription) {
            if ($subscription->workerId->equals($workerId)) {
                $this->remove($subscription->subscriptionId);
            }
        }
    }

    public function findRoutes(SubscriptionKind $kind, string $key): SubscriptionRoutes
    {
        $idsByWorker = [];

        foreach ($this->index->findMatching($kind, $key) as $subscriptionId) {
            $subscription = $this->subscriptions[$subscriptionId->value] ?? null;

            if ($subscription !== null) {
                $idsByWorker[$subscription->workerId->value][] = $subscriptionId;
            }
        }

        ksort($idsByWorker);

        return new SubscriptionRoutes(array_map(SubscriptionIdCollection::fromIds(...), $idsByWorker));
    }

    public function listSubscriptions(): BrokerSubscriptionCollection
    {
        return BrokerSubscriptionCollection::fromSubscriptions($this->subscriptions);
    }

    public function getRoutingStats(): RoutingStats
    {
        return $this->index->getRoutingStats();
    }
}
