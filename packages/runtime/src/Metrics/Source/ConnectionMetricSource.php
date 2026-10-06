<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Lifecycle\ConnectionPhase;
use Stewart\Runtime\Metrics\Exposition\Collection\MetricFamilyCollection;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class ConnectionMetricSource implements RuntimeMetricSource
{
    public function collectMetrics(RuntimeSnapshot $snapshot): MetricFamilyCollection
    {
        $connection = $snapshot->connection;
        $none = MetricLabels::none();
        $phases = MetricFamily::createGauge('stewart_ha_connection_phase', 'Home Assistant connection phase; 1 for the current one.');

        foreach (ConnectionPhase::cases() as $phase) {
            $phases = $phases->withSample(MetricLabels::withSingleLabel('phase', $phase->value), $phase === $connection->phase);
        }

        $lastOutage = MetricFamily::createGauge('stewart_ha_last_outage_seconds', 'Length of the last Home Assistant outage.');

        if ($connection->lastOutage !== null) {
            $lastOutage = $lastOutage->withSample($none, $connection->lastOutage->toSeconds());
        }

        return MetricFamilyCollection::fromFamilies([
            $phases,
            MetricFamily::createCounter('stewart_ha_reconnects_total', 'Home Assistant reconnects since the daemon started.')->withSample($none, $connection->reconnects),
            $lastOutage,
        ]);
    }
}
