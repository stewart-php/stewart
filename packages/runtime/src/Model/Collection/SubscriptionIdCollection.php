<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Model\SubscriptionId;

/** @extends ListCollection<SubscriptionId> */
final readonly class SubscriptionIdCollection extends ListCollection
{
    /** @param iterable<SubscriptionId> $subscriptionIds */
    public static function fromIds(iterable $subscriptionIds): self
    {
        return self::fromList($subscriptionIds);
    }

    /** @return list<string> */
    public function toStrings(): array
    {
        return $this->mapToList(static fn(SubscriptionId $subscriptionId): string => $subscriptionId->value);
    }
}
