<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Metrics\Exposition\Collection\MetricFamilyCollection;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\MetricType;
use Stewart\Runtime\Metrics\Exposition\PrometheusNumber;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class StoreMetricSource implements RuntimeMetricSource
{
    public function collectMetrics(RuntimeSnapshot $snapshot): MetricFamilyCollection
    {
        $store = $snapshot->store;
        $available = new MetricFamily('stewart_store_available', 'Whether workers reach the store; 1 or 0.', MetricType::Gauge);
        $lastFailure = new MetricFamily('stewart_store_last_failure_timestamp_seconds', 'Unix time of the last store failure.', MetricType::Gauge);

        if ($store !== null) {
            $available->recordSample(MetricLabels::none(), $store->available);
        }

        if ($store?->lastFailureAt !== null) {
            $lastFailure->recordSample(MetricLabels::none(), PrometheusNumber::convertToEpochSeconds($store->lastFailureAt));
        }

        return MetricFamilyCollection::fromFamilies([$available, $lastFailure]);
    }
}
