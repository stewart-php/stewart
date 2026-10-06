<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Exposition;

use Stewart\Runtime\Metrics\Exposition\Collection\MetricSampleCollection;

final class MetricFamily
{
    private const string BUCKET_BOUND_LABEL = 'le';

    /** @var list<MetricSample> */
    private array $samples = [];

    /** @param non-empty-string $name */
    public function __construct(
        public readonly string $name,
        public readonly string $help,
        public readonly MetricType $type,
    ) {}

    /** @param non-empty-string $name */
    public static function createWithSample(string $name, string $help, MetricType $type, MetricLabels $labels, int|float|bool $value): self
    {
        $family = new self($name, $help, $type);
        $family->recordSample($labels, $value);

        return $family;
    }

    public function recordSample(MetricLabels $labels, int|float|bool $value): void
    {
        $this->samples[] = new MetricSample(MetricSampleSuffix::None, $labels, (float) $value);
    }

    public function recordHistogram(MetricLabels $labels, Histogram $histogram): void
    {
        foreach ($histogram->buckets as $bucket) {
            $bucketLabels = $labels->withLabel(self::BUCKET_BOUND_LABEL, PrometheusNumber::formatValue($bucket->upperBound));
            $this->samples[] = new MetricSample(MetricSampleSuffix::Bucket, $bucketLabels, $bucket->cumulativeCount);
        }

        $this->samples[] = new MetricSample(MetricSampleSuffix::Sum, $labels, $histogram->sum->toSeconds());
        $this->samples[] = new MetricSample(MetricSampleSuffix::Count, $labels, $histogram->count);
    }

    public function hasSamples(): bool
    {
        return $this->samples !== [];
    }

    public function listSamples(): MetricSampleCollection
    {
        return MetricSampleCollection::fromSamples($this->samples);
    }
}
