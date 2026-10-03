<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model;

final readonly class RoutingStats
{
    public function __construct(
        public int $subscriptions,
        public int $patterns,
        public int $cachedKeys,
        public int $cacheHits,
        public int $cacheMisses,
    ) {}
}
