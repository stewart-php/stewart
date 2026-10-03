<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

interface SubscriptionListener
{
    public function subscriptionRegistered(RegisteredSubscription $subscription): void;

    public function subscriptionCancelled(RegisteredSubscription $subscription): void;
}
