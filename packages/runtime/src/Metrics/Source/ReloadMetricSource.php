<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Lifecycle\ReloadOutcome;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\MetricType;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class ReloadMetricSource implements RuntimeMetricSource
{
    /** @return list<MetricFamily> */
    public function collectMetrics(RuntimeSnapshot $snapshot): array
    {
        $reloads = new MetricFamily('stewart_reloads_total', 'Reloads since the daemon started, by outcome.', MetricType::Counter, ['outcome']);
        $reload = $snapshot->reload;

        foreach (ReloadOutcome::cases() as $outcome) {
            $reloads->recordSample(MetricLabels::fromLabel('outcome', $outcome->value), match ($outcome) {
                ReloadOutcome::Applied => $reload->applied ?? 0,
                ReloadOutcome::PartlyApplied => $reload->partlyApplied ?? 0,
                ReloadOutcome::Failed => $reload->failed ?? 0,
            });
        }

        return [$reloads];
    }
}
