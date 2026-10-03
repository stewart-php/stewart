<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Closure;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Stream\SubscriptionScope;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;

final readonly class RegisteredSubscription
{
    public function __construct(
        public SubscriptionId $id,
        public ResourceScope $scope,
        public SubscriptionKind $kind,
        public Selector $selector,
        public SubscriptionScope $subscriptionScope,
        public Closure $handler,
    ) {}
}
