<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Lifecycle\ConnectionPhase;
use Stewart\Runtime\Metrics\Exposition\Collection\MetricFamilyCollection;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\MetricType;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class ConnectionMetricSource implements RuntimeMetricSource
{
    public function collectMetrics(RuntimeSnapshot $snapshot): MetricFamilyCollection
    {
        $connection = $snapshot->connection;
        $phases = new MetricFamily('stewart_ha_connection_phase', 'Home Assistant connection phase; 1 for the current one.', MetricType::Gauge);

        foreach (ConnectionPhase::cases() as $phase) {
            $phases->recordSample(MetricLabels::fromLabel('phase', $phase->value), $phase === $connection->phase);
        }

        $lastOutage = new MetricFamily('stewart_ha_last_outage_seconds', 'Length of the last Home Assistant outage.', MetricType::Gauge);

        if ($connection->lastOutage !== null) {
            $lastOutage->recordSample(MetricLabels::none(), $connection->lastOutage->toSeconds());
        }

        return MetricFamilyCollection::fromFamilies([
            $phases,
            MetricFamily::createWithSample('stewart_ha_reconnects_total', 'Home Assistant reconnects since the daemon started.', MetricType::Counter, MetricLabels::none(), $connection->reconnects),
            $lastOutage,
        ]);
    }
}
