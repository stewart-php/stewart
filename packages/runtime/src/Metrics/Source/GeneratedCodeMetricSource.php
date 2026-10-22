<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\MetricType;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class GeneratedCodeMetricSource implements RuntimeMetricSource
{
    /** @return list<MetricFamily> */
    public function collectMetrics(RuntimeSnapshot $snapshot): array
    {
        $generated = $snapshot->generated;
        $drift = new MetricFamily('stewart_generated_entities_drift', 'Home Assistant entities the generated classes miss (added) or still have (removed).', MetricType::Gauge, ['change']);
        $formatCurrent = new MetricFamily('stewart_generated_format_current', 'Whether stewart generate wrote the generated classes in the current format; 1 or 0.', MetricType::Gauge, []);

        if ($generated !== null) {
            $drift->recordSample(MetricLabels::fromLabel('change', 'added'), \count($generated->added));
            $drift->recordSample(MetricLabels::fromLabel('change', 'removed'), \count($generated->removed));
            $formatCurrent->recordSample(MetricLabels::none(), $generated->hasCurrentFormat());
        }

        return [$drift, $formatCurrent];
    }
}
