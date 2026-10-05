<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Trigger;

use Stewart\Runtime\Broker\BrokerSubscription;
use Stewart\Runtime\Broker\BrokerSubscriptionListener;
use Stewart\Runtime\Broker\HaSession;

// Home Assistant sees each trigger spec once, however many app subscriptions share it.
final class TriggerSubscribers implements BrokerSubscriptionListener
{
    /** @var array<string, int> */
    private array $subscriberCountsByKey = [];

    public function __construct(private readonly HaSession $session) {}

    public function subscriptionAdded(BrokerSubscription $subscription): void
    {
        if ($subscription->trigger === null) {
            return;
        }

        $key = $subscription->trigger->getSharingKey();
        $this->subscriberCountsByKey[$key] = ($this->subscriberCountsByKey[$key] ?? 0) + 1;

        if ($this->subscriberCountsByKey[$key] === 1) {
            $this->session->subscribeTrigger($subscription->trigger);
        }
    }

    public function subscriptionRemoved(BrokerSubscription $subscription): void
    {
        if ($subscription->trigger === null) {
            return;
        }

        $key = $subscription->trigger->getSharingKey();

        if (!isset($this->subscriberCountsByKey[$key])) {
            return;
        }

        if (--$this->subscriberCountsByKey[$key] > 0) {
            return;
        }

        unset($this->subscriberCountsByKey[$key]);
        $this->session->unsubscribeTrigger($subscription->trigger);
    }
}
