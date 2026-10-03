<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Assembler;

use Stewart\Runtime\Broker\BrokerSubscription;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Control\Protocol\Status\Collection\RegistrationInfoCollection;
use Stewart\Runtime\Control\Protocol\Status\RegistrationInfo;

final readonly class RegistrationInfoBuilder
{
    public function __construct(private SubscriptionRegistry $registry) {}

    public function buildRegistrationInfos(): RegistrationInfoCollection
    {
        return RegistrationInfoCollection::fromInfos($this->registry->listSubscriptions()->mapToList($this->describeSubscription(...)));
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
