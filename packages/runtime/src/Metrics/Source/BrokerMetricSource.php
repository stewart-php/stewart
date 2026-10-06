<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Metrics\Exposition\Collection\MetricFamilyCollection;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\MetricType;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class BrokerMetricSource implements RuntimeMetricSource
{
    public function collectMetrics(RuntimeSnapshot $snapshot): MetricFamilyCollection
    {
        $broker = $snapshot->broker;
        $routing = $broker->routing;
        $none = MetricLabels::none();

        return MetricFamilyCollection::fromFamilies([
            MetricFamily::createWithSample('stewart_workers', 'Worker slots the broker supervises.', MetricType::Gauge, $none, $broker->workers),
            MetricFamily::createWithSample('stewart_workers_live', 'Worker processes currently running.', MetricType::Gauge, $none, $broker->liveWorkers),
            MetricFamily::createWithSample('stewart_service_calls_in_flight', 'Home Assistant calls awaiting an answer.', MetricType::Gauge, $none, $broker->inFlightServiceCalls),
            MetricFamily::createWithSample('stewart_service_calls_refused_total', 'Home Assistant calls refused for lack of a free slot.', MetricType::Counter, $none, $broker->refusedServiceCalls),
            MetricFamily::createWithSample('stewart_routing_subscriptions', 'Subscriptions in the broker routing index.', MetricType::Gauge, $none, $routing->subscriptions),
            MetricFamily::createWithSample('stewart_routing_patterns', 'Wildcard patterns in the broker routing index.', MetricType::Gauge, $none, $routing->patterns),
            MetricFamily::createWithSample('stewart_routing_cached_keys', 'Routing keys with a cached match.', MetricType::Gauge, $none, $routing->cachedKeys),
            MetricFamily::createWithSample('stewart_routing_cache_hits_total', 'Routing lookups answered from the cache.', MetricType::Counter, $none, $routing->cacheHits),
            MetricFamily::createWithSample('stewart_routing_cache_misses_total', 'Routing lookups that missed the cache.', MetricType::Counter, $none, $routing->cacheMisses),
        ]);
    }
}
