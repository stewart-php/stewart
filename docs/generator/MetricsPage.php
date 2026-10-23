<?php

declare(strict_types=1);

namespace Stewart\Docs\Generator;

use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\RuntimeMetricSource;

final readonly class MetricsPage implements ReferencePage
{
    /** @param list<RuntimeMetricSource> $sources */
    public function __construct(
        private array $sources,
        private RuntimeSnapshot $snapshot,
    ) {}

    public function getFileName(): string
    {
        return 'reference/metrics.md';
    }

    public function renderMarkdown(): string
    {
        $families = [];

        foreach ($this->sources as $source) {
            array_push($families, ...$source->collectMetrics($this->snapshot));
        }

        usort($families, static fn(MetricFamily $a, MetricFamily $b): int => $a->name <=> $b->name);
        $rows = array_map(
            fn(MetricFamily $family): array => [MarkdownTable::formatCode($family->name), $family->type->value, $this->formatLabels($family), $family->help],
            $families,
        );

        return new MarkdownPage(
            'Metrics reference',
            'Every Prometheus metric Stewart serves on its metrics port.',
            4,
            implode("\n", [
                'Stewart serves these metrics at `GET /metrics` in the Prometheus text format, on the metrics port',
                '(`http.metrics.listen`, with an optional token of its own).',
                '[Status and control](../guides/status.md#metrics) explains how to turn the port on and scrape it.',
                '',
                MarkdownTable::formatTable(['Metric', 'Type', 'Labels', 'Meaning'], $rows),
            ]),
        )->renderMarkdown();
    }

    private function formatLabels(MetricFamily $family): string
    {
        return implode(', ', array_map(MarkdownTable::formatCode(...), $family->labelNames));
    }
}
