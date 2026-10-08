<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Source;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Metrics\Exposition\Collection\MetricFamilyCollection;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\MetricType;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class DeployMetricSource implements RuntimeMetricSource
{
    public function collectMetrics(RuntimeSnapshot $snapshot): MetricFamilyCollection
    {
        $deploy = $snapshot->deploy;
        $info = new MetricFamily('stewart_deploy_info', 'Commit the daemon runs while it polls for new ones; always 1.', MetricType::Gauge);
        $failures = new MetricFamily('stewart_deploy_failures_total', 'New commits that failed stewart check and were not deployed.', MetricType::Counter);

        if ($deploy !== null) {
            $info->recordSample(MetricLabels::fromLabel('commit', $deploy->commit), 1);
            $failures->recordSample(MetricLabels::none(), $deploy->failures);
        }

        return MetricFamilyCollection::fromFamilies([$info, $failures]);
    }
}
