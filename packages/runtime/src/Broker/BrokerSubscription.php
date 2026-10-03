<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\Selector\Selector;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Model\WorkerId;

final readonly class BrokerSubscription
{
    public function __construct(
        public SubscriptionId $subscriptionId,
        public WorkerId $workerId,
        public ResourceScope $scope,
        public SubscriptionKind $kind,
        public Selector $selector,
    ) {}
}
