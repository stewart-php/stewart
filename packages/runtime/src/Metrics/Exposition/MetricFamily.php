<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Exposition;

use Stewart\Runtime\Metrics\Exposition\Collection\MetricSampleCollection;

final readonly class MetricFamily
{
    private const string BUCKET_BOUND_LABEL = 'le';

    /** @param non-empty-string $name */
    private function __construct(
        public string $name,
        public string $help,
        public MetricType $type,
        public MetricSampleCollection $samples,
    ) {}

    /** @param non-empty-string $name */
    public static function createCounter(string $name, string $help): self
    {
        return new self($name, $help, MetricType::Counter, MetricSampleCollection::empty());
    }

    /** @param non-empty-string $name */
    public static function createGauge(string $name, string $help): self
    {
        return new self($name, $help, MetricType::Gauge, MetricSampleCollection::empty());
    }

    /** @param non-empty-string $name */
    public static function createHistogram(string $name, string $help): self
    {
        return new self($name, $help, MetricType::Histogram, MetricSampleCollection::empty());
    }

    public function withSample(MetricLabels $labels, int|float|bool $value): self
    {
        return $this->withAppendedSample(new MetricSample(MetricSampleSuffix::None, $labels, (float) $value));
    }

    public function withHistogram(MetricLabels $labels, HistogramBuckets $buckets): self
    {
        $family = $this;

        foreach ($buckets->upperBounds as $index => $upperBound) {
            $bucketLabels = $labels->withLabel(self::BUCKET_BOUND_LABEL, PrometheusNumber::formatValue($upperBound));
            $family = $family->withAppendedSample(new MetricSample(MetricSampleSuffix::Bucket, $bucketLabels, $buckets->cumulativeCounts[$index] ?? $buckets->count));
        }

        return $family
            ->withAppendedSample(new MetricSample(MetricSampleSuffix::Sum, $labels, $buckets->sum->toSeconds()))
            ->withAppendedSample(new MetricSample(MetricSampleSuffix::Count, $labels, $buckets->count));
    }

    private function withAppendedSample(MetricSample $sample): self
    {
        return new self($this->name, $this->help, $this->type, $this->samples->withAppendedSample($sample));
    }
}
