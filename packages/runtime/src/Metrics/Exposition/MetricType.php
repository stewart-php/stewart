<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Exposition;

enum MetricType: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
    case Histogram = 'histogram';
}
