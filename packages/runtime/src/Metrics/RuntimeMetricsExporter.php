<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics;

use Stewart\Runtime\Control\SnapshotSource;

final readonly class RuntimeMetricsExporter
{
    public function __construct(
        private SnapshotSource $snapshots,
        private RuntimeMetricsCollector $collector,
        private PrometheusTextEncoder $encoder,
    ) {}

    public function exportMetrics(): string
    {
        return $this->encoder->encodeFamilies($this->collector->collectMetrics($this->snapshots->takeSnapshot()));
    }
}
