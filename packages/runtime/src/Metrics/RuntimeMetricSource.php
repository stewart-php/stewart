<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Metrics\Exposition\Collection\MetricFamilyCollection;

interface RuntimeMetricSource
{
    public function collectMetrics(RuntimeSnapshot $snapshot): MetricFamilyCollection;
}
