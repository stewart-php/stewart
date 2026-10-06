<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Metrics\Exposition\Collection\MetricFamilyCollection;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\PrometheusNumber;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class DaemonMetricSource implements RuntimeMetricSource
{
    public function collectMetrics(RuntimeSnapshot $snapshot): MetricFamilyCollection
    {
        $daemon = $snapshot->daemon;
        $none = MetricLabels::none();
        $build = MetricLabels::withSingleLabel('version', $daemon->version)->withLabel('ha_version', $daemon->haVersion ?? '');

        return MetricFamilyCollection::fromFamilies([
            MetricFamily::createGauge('stewart_build_info', 'Stewart and Home Assistant versions; always 1.')->withSample($build, 1),
            MetricFamily::createGauge('stewart_start_time_seconds', 'Unix time the daemon started.')
                ->withSample($none, PrometheusNumber::convertToEpochSeconds($daemon->startedAt)),
            MetricFamily::createGauge('stewart_broker_memory_bytes', 'Memory the broker process holds.')->withSample($none, $daemon->memoryBytes),
            MetricFamily::createGauge('stewart_ha_entities', 'Entities in the Home Assistant state cache.')->withSample($none, $daemon->entities),
        ]);
    }
}
