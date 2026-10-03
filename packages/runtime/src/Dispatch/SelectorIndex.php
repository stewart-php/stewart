<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

use Stewart\Contracts\Selector\Selector;
use Stewart\Runtime\Model\Collection\SubscriptionIdCollection;
use Stewart\Runtime\Model\RoutingStats;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;

final class SelectorIndex
{
    private const int CACHE_LIMIT = 10_000;

    /** @var array<string, IndexedSelector> */
    private array $entries = [];

    /** @var array<string, IndexedSelector> */
    private array $patterns = [];

    /** @var array<string, array<string, SubscriptionId>> */
    private array $exact = [];

    /** @var array<string, SubscriptionIdCollection> */
    private array $cache = [];

    private int $cacheHits = 0;

    private int $cacheMisses = 0;

    public function add(SubscriptionId $subscriptionId, SubscriptionKind $kind, Selector $selector): void
    {
        $this->remove($subscriptionId);

        $entry = new IndexedSelector($subscriptionId, $kind, $selector);
        $this->entries[$subscriptionId->value] = $entry;
        $exactKey = $selector->findExactPattern();

        if ($exactKey === null) {
            $this->patterns[$subscriptionId->value] = $entry;
        } else {
            $this->exact[$this->buildCacheKey($kind, $exactKey)][$subscriptionId->value] = $subscriptionId;
        }

        $this->cache = [];
    }

    public function remove(SubscriptionId $subscriptionId): void
    {
        $entry = $this->entries[$subscriptionId->value] ?? null;

        if ($entry === null) {
            return;
        }

        unset($this->entries[$subscriptionId->value], $this->patterns[$subscriptionId->value]);

        $exactKey = $entry->selector->findExactPattern();

        if ($exactKey !== null) {
            $cacheKey = $this->buildCacheKey($entry->kind, $exactKey);
            unset($this->exact[$cacheKey][$subscriptionId->value]);

            if (($this->exact[$cacheKey] ?? null) === []) {
                unset($this->exact[$cacheKey]);
            }
        }

        $this->cache = [];
    }

    public function findMatching(SubscriptionKind $kind, string $key): SubscriptionIdCollection
    {
        $cacheKey = $this->buildCacheKey($kind, $key);
        $cached = $this->cache[$cacheKey] ?? null;

        if ($cached !== null) {
            ++$this->cacheHits;

            return $cached;
        }

        ++$this->cacheMisses;

        $matched = array_values($this->exact[$cacheKey] ?? []);

        foreach ($this->patterns as $entry) {
            if ($entry->kind === $kind && $entry->selector->matches($key)) {
                $matched[] = $entry->subscriptionId;
            }
        }

        if (\count($this->cache) >= self::CACHE_LIMIT) {
            $this->cache = [];
        }

        return $this->cache[$cacheKey] = SubscriptionIdCollection::fromIds($matched);
    }

    public function getRoutingStats(): RoutingStats
    {
        return new RoutingStats(
            subscriptions: \count($this->entries),
            patterns: \count($this->patterns),
            cachedKeys: \count($this->cache),
            cacheHits: $this->cacheHits,
            cacheMisses: $this->cacheMisses,
        );
    }

    private function buildCacheKey(SubscriptionKind $kind, string $key): string
    {
        return $kind->value . "\0" . $key;
    }
}
