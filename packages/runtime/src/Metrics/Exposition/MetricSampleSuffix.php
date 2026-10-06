<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Exposition;

enum MetricSampleSuffix: string
{
    case None = '';
    case Bucket = '_bucket';
    case Sum = '_sum';
    case Count = '_count';
}
