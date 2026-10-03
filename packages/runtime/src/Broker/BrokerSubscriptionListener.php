<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

interface BrokerSubscriptionListener
{
    public function subscriptionAdded(BrokerSubscription $subscription): void;

    public function subscriptionRemoved(BrokerSubscription $subscription): void;
}
