<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Stewart\Contracts\Selector\Selector;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;

final readonly class IndexedSelector
{
    public function __construct(
        public SubscriptionId $subscriptionId,
        public SubscriptionKind $kind,
        public Selector $selector,
    ) {}
}
