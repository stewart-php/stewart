<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Metrics\Exposition\Collection\MetricFamilyCollection;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\PrometheusNumber;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class StoreMetricSource implements RuntimeMetricSource
{
    public function collectMetrics(RuntimeSnapshot $snapshot): MetricFamilyCollection
    {
        $store = $snapshot->store;
        $none = MetricLabels::none();
        $available = MetricFamily::createGauge('stewart_store_available', 'Whether workers reach the store; 1 or 0.');
        $lastFailure = MetricFamily::createGauge('stewart_store_last_failure_timestamp_seconds', 'Unix time of the last store failure.');

        if ($store !== null) {
            $available = $available->withSample($none, $store->available);
        }

        if ($store?->lastFailureAt !== null) {
            $lastFailure = $lastFailure->withSample($none, PrometheusNumber::convertToEpochSeconds($store->lastFailureAt));
        }

        return MetricFamilyCollection::fromFamilies([$available, $lastFailure]);
    }
}
