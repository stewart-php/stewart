<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\MetricType;
use Stewart\Runtime\Metrics\Exposition\PrometheusNumber;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class DaemonMetricSource implements RuntimeMetricSource
{
    /** @return list<MetricFamily> */
    public function collectMetrics(RuntimeSnapshot $snapshot): array
    {
        $daemon = $snapshot->daemon;
        $none = MetricLabels::none();
        $build = MetricLabels::fromLabel('version', $daemon->version)->withLabel('ha_version', $daemon->haVersion ?? '');

        return [
            MetricFamily::createWithSample('stewart_build_info', 'Stewart and Home Assistant versions; always 1.', MetricType::Gauge, $build, 1),
            MetricFamily::createWithSample('stewart_start_time_seconds', 'Unix time the daemon started.', MetricType::Gauge, $none, PrometheusNumber::convertToEpochSeconds($daemon->startedAt)),
            MetricFamily::createWithSample('stewart_broker_memory_bytes', 'Resident memory (RSS) of the broker process.', MetricType::Gauge, $none, $daemon->memoryBytes),
            MetricFamily::createWithSample('stewart_ha_entities', 'Entities in the Home Assistant state cache.', MetricType::Gauge, $none, $daemon->entities),
        ];
    }
}
