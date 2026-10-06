<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Metrics\Exposition\Collection\MetricFamilyCollection;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class BrokerMetricSource implements RuntimeMetricSource
{
    public function collectMetrics(RuntimeSnapshot $snapshot): MetricFamilyCollection
    {
        $broker = $snapshot->broker;
        $routing = $broker->routing;
        $none = MetricLabels::none();

        return MetricFamilyCollection::fromFamilies([
            MetricFamily::createGauge('stewart_workers', 'Worker slots the broker supervises.')->withSample($none, $broker->workers),
            MetricFamily::createGauge('stewart_workers_live', 'Worker processes currently running.')->withSample($none, $broker->liveWorkers),
            MetricFamily::createGauge('stewart_service_calls_in_flight', 'Home Assistant calls awaiting an answer.')->withSample($none, $broker->inFlightServiceCalls),
            MetricFamily::createCounter('stewart_service_calls_refused_total', 'Home Assistant calls refused for lack of a free slot.')->withSample($none, $broker->refusedServiceCalls),
            MetricFamily::createGauge('stewart_routing_subscriptions', 'Subscriptions in the broker routing index.')->withSample($none, $routing->subscriptions),
            MetricFamily::createGauge('stewart_routing_patterns', 'Wildcard patterns in the broker routing index.')->withSample($none, $routing->patterns),
            MetricFamily::createGauge('stewart_routing_cached_keys', 'Routing keys with a cached match.')->withSample($none, $routing->cachedKeys),
            MetricFamily::createCounter('stewart_routing_cache_hits_total', 'Routing lookups answered from the cache.')->withSample($none, $routing->cacheHits),
            MetricFamily::createCounter('stewart_routing_cache_misses_total', 'Routing lookups that missed the cache.')->withSample($none, $routing->cacheMisses),
        ]);
    }
}
