<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Broker\BrokerSubscription;

/** @extends ListCollection<BrokerSubscription> */
final readonly class BrokerSubscriptionCollection extends ListCollection
{
    /** @param iterable<BrokerSubscription> $subscriptions */
    public static function fromSubscriptions(iterable $subscriptions): self
    {
        return self::fromList($subscriptions);
    }
}
