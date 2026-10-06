<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Exposition;

final readonly class MetricSample
{
    public function __construct(
        public MetricSampleSuffix $suffix,
        public MetricLabels $labels,
        public float $value,
    ) {}
}
