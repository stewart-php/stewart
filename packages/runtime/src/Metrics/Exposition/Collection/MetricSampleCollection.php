<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Exposition\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Metrics\Exposition\MetricSample;

/** @extends ListCollection<MetricSample> */
final readonly class MetricSampleCollection extends ListCollection
{
    /** @param iterable<MetricSample> $samples */
    public static function fromSamples(iterable $samples): self
    {
        return self::fromList($samples);
    }

    public function withAppendedSample(MetricSample $sample): self
    {
        return $this->withAppendedElement($sample);
    }
}
