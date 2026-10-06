<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\EntityFilter;
use Stewart\Runtime\Model\Collection\SubscriptionIdCollection;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Registry\RegistryCache;

final class EntityFilterIndex
{
    private const int CACHE_LIMIT = 10_000;

    /** @var array<string, EntityFilter> */
    private array $filters = [];

    /** @var array<string, SubscriptionId> */
    private array $subscriptionIds = [];

    /** @var array<string, SubscriptionIdCollection> */
    private array $cache = [];

    private int $cachedRevision = 0;

    public function __construct(private readonly RegistryCache $registry) {}

    public function add(SubscriptionId $subscriptionId, EntityFilter $filter): void
    {
        $this->filters[$subscriptionId->value] = $filter;
        $this->subscriptionIds[$subscriptionId->value] = $subscriptionId;
        $this->cache = [];
    }

    public function remove(SubscriptionId $subscriptionId): void
    {
        unset($this->filters[$subscriptionId->value], $this->subscriptionIds[$subscriptionId->value]);
        $this->cache = [];
    }

    public function findMatching(EntityId $entityId): SubscriptionIdCollection
    {
        if ($this->filters === []) {
            return SubscriptionIdCollection::empty();
        }

        if ($this->cachedRevision !== $this->registry->getRevision() || \count($this->cache) >= self::CACHE_LIMIT) {
            $this->cache = [];
            $this->cachedRevision = $this->registry->getRevision();
        }

        return $this->cache[$entityId->value] ??= $this->matchFilters($entityId);
    }

    private function matchFilters(EntityId $entityId): SubscriptionIdCollection
    {
        $matched = [];

        foreach ($this->filters as $key => $filter) {
            if ($filter->matchesEntity($entityId, $this->registry)) {
                $matched[] = $this->subscriptionIds[$key];
            }
        }

        return SubscriptionIdCollection::fromIds($matched);
    }
}
