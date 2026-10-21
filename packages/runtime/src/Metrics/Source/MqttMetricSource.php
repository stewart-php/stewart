<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Lifecycle\ConnectionPhase;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\MetricType;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class MqttMetricSource implements RuntimeMetricSource
{
    /** @return list<MetricFamily> */
    public function collectMetrics(RuntimeSnapshot $snapshot): array
    {
        $mqtt = $snapshot->mqtt;
        $phases = new MetricFamily('stewart_mqtt_connection_phase', 'MQTT connection phase; 1 for the current one.', MetricType::Gauge, ['phase']);
        $reconnects = new MetricFamily('stewart_mqtt_reconnects_total', 'MQTT reconnects since the daemon started.', MetricType::Counter, []);
        $dropped = new MetricFamily('stewart_mqtt_messages_dropped_total', 'MQTT messages dropped by reason.', MetricType::Counter, ['reason']);
        $queued = new MetricFamily('stewart_mqtt_outbound_queued', 'QoS 1 messages waiting for the server to acknowledge them.', MetricType::Gauge, []);

        if ($mqtt !== null) {
            foreach (ConnectionPhase::cases() as $phase) {
                $phases->recordSample(MetricLabels::fromLabel('phase', $phase->value), $phase === $mqtt->phase);
            }

            foreach ($mqtt->dropped as $drops) {
                $dropped->recordSample(MetricLabels::fromLabel('reason', $drops->reason->value), $drops->count);
            }

            $reconnects->recordSample(MetricLabels::none(), $mqtt->reconnects);
            $queued->recordSample(MetricLabels::none(), $mqtt->outboundQueued);
        }

        return [$phases, $reconnects, $dropped, $queued];
    }
}
