<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Mqtt;

use Stewart\Runtime\Broker\BrokerSubscription;
use Stewart\Runtime\Broker\BrokerSubscriptionListener;
use Stewart\Runtime\Model\SubscriptionKind;

// The server sees each filter once, however many app subscriptions share it.
final class MqttFilterSubscriptions implements BrokerSubscriptionListener
{
    /** @var array<string, int> */
    private array $subscriberCountsByFilter = [];

    public function __construct(private readonly MqttLink $link) {}

    public function subscriptionAdded(BrokerSubscription $subscription): void
    {
        if ($subscription->kind !== SubscriptionKind::Mqtt) {
            return;
        }

        $filter = $subscription->selector->getPattern();
        $this->subscriberCountsByFilter[$filter] = ($this->subscriberCountsByFilter[$filter] ?? 0) + 1;

        if ($this->subscriberCountsByFilter[$filter] === 1) {
            $this->link->subscribeFilter($filter);
        }
    }

    public function subscriptionRemoved(BrokerSubscription $subscription): void
    {
        if ($subscription->kind !== SubscriptionKind::Mqtt) {
            return;
        }

        $filter = $subscription->selector->getPattern();

        if (!isset($this->subscriberCountsByFilter[$filter])) {
            return;
        }

        if (--$this->subscriberCountsByFilter[$filter] > 0) {
            return;
        }

        unset($this->subscriberCountsByFilter[$filter]);
        $this->link->unsubscribeFilter($filter);
    }
}
