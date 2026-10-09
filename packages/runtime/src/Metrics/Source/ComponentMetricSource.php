<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Runtime\Metrics\Exposition\Collection\MetricFamilyCollection;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\MetricType;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class ComponentMetricSource implements RuntimeMetricSource
{
    public function collectMetrics(RuntimeSnapshot $snapshot): MetricFamilyCollection
    {
        $component = $snapshot->component;
        $info = new MetricFamily('stewart_component_info', 'Version and protocol of the stewart Home Assistant integration; always 1.', MetricType::Gauge);
        $states = new MetricFamily('stewart_component_state', 'State of the stewart Home Assistant integration; 1 for the current one.', MetricType::Gauge);

        if ($component?->version !== null && $component->protocol !== null) {
            $info->recordSample(MetricLabels::fromLabel('version', $component->version)->withLabel('protocol', (string) $component->protocol), 1);
        }

        if ($component !== null) {
            foreach (ComponentState::cases() as $state) {
                $states->recordSample(MetricLabels::fromLabel('state', $state->value), $state === $component->state);
            }
        }

        return MetricFamilyCollection::fromFamilies([$info, $states]);
    }
}
