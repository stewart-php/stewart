<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;

final readonly class RuntimeMetricsCollector
{
    /** @param iterable<RuntimeMetricSource> $runtimeMetricSources */
    public function __construct(private iterable $runtimeMetricSources) {}

    /** @return list<MetricFamily> */
    public function collectMetrics(RuntimeSnapshot $snapshot): array
    {
        return iterator_to_array($this->yieldFamilies($snapshot), false);
    }

    /** @return iterable<MetricFamily> */
    private function yieldFamilies(RuntimeSnapshot $snapshot): iterable
    {
        foreach ($this->runtimeMetricSources as $source) {
            yield from $source->collectMetrics($snapshot);
        }
    }
}
