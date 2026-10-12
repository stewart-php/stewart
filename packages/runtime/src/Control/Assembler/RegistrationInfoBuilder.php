<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Assembler;

use Stewart\Runtime\Broker\BrokerSubscription;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Control\Protocol\Status\RegistrationInfo;

final readonly class RegistrationInfoBuilder
{
    public function __construct(private SubscriptionRegistry $registry) {}

    /** @return list<RegistrationInfo> */
    public function buildRegistrationInfos(): array
    {
        return array_map($this->describeSubscription(...), $this->registry->listSubscriptions());
    }

    private function describeSubscription(BrokerSubscription $subscription): RegistrationInfo
    {
        return new RegistrationInfo(
            subscriptionId: $subscription->subscriptionId->value,
            workerId: $subscription->workerId->value,
            appId: $subscription->scope->wireValue(),
            kind: $subscription->kind,
            selector: $subscription->selector->toCanonicalKey(),
            exact: $subscription->selector->findExactPattern() !== null,
        );
    }
}
