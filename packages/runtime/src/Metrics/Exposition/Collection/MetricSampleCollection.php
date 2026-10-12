<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Exposition\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Metrics\Exposition\MetricSample;

/** @extends ListCollection<MetricSample> */
final readonly class MetricSampleCollection extends ListCollection {}
