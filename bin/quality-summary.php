<?php

declare(strict_types=1);

// Writes the headline quality numbers to summary.json and summary.md in the reports directory, next to a baseline.

final readonly class QualityMetric
{
    public function __construct(
        public string $key,
        public string $label,
        public ?bool $higherIsBetter,
        public int $decimals,
    ) {}

    public function formatValue(float $value): string
    {
        return number_format($value, $this->decimals, '.', '');
    }

    public function formatChange(float $current, float $baseline): string
    {
        $change = round($current - $baseline, $this->decimals);

        if ($change === 0.0) {
            return '';
        }

        $signed = ($change > 0 ? '+' : '') . $this->formatValue($change);

        if ($this->higherIsBetter === null) {
            return $signed;
        }

        return (($change > 0) === $this->higherIsBetter ? '🟢 ' : '🔴 ') . $signed;
    }
}

final readonly class QualitySnapshot
{
    /** @param array<string, float> $values */
    public function __construct(public array $values) {}

    public static function readFromReports(string $reportsDirectory): self
    {
        $values = [];
        $clover = @simplexml_load_file($reportsDirectory . '/coverage/clover.xml');

        if ($clover !== false) {
            $totals = $clover->project->metrics;
            $values['coverage_lines'] = 100 * (float) $totals['coveredstatements'] / max(1.0, (float) $totals['statements']);
            $values['coverage_methods'] = 100 * (float) $totals['coveredmethods'] / max(1.0, (float) $totals['methods']);
        }

        $metrics = self::readJsonFile($reportsDirectory . '/metrics/summary.json');
        $values['classes'] = self::findNumber($metrics, 'OOP', 'classes');
        $values['logical_lines'] = self::findNumber($metrics, 'LOC', 'logicalLinesOfCode');
        $values['cyclomatic_complexity'] = self::findNumber($metrics, 'Complexity', 'avgCyclomaticComplexityByClass');
        $values['violation_errors'] = self::findNumber($metrics, 'Violations', 'error');
        $values['violation_warnings'] = self::findNumber($metrics, 'Violations', 'warning');
        $values['covered_msi'] = self::findNumber(self::readJsonFile($reportsDirectory . '/mutation/infection.json'), 'stats', 'coveredCodeMsi');

        return new self(array_filter($values, static fn(?float $value): bool => $value !== null));
    }

    public static function readFromSummaryFile(string $path): ?self
    {
        $summary = self::readJsonFile($path);

        if (!is_array($summary) || !is_array($summary['values'] ?? null)) {
            return null;
        }

        $values = [];

        foreach ($summary['values'] as $key => $value) {
            if (is_string($key) && is_numeric($value)) {
                $values[$key] = (float) $value;
            }
        }

        return new self($values);
    }

    public function writeToSummaryFile(string $path): void
    {
        file_put_contents($path, json_encode(['values' => $this->values], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR) . "\n");
    }

    public function writeBadgeFile(string $path, QualityMetric $metric, string $badgeLabel): void
    {
        $value = $this->values[$metric->key] ?? null;

        if ($value === null) {
            return;
        }

        $color = match (true) {
            $value >= 80 => 'brightgreen',
            $value >= 60 => 'yellow',
            default => 'red',
        };

        file_put_contents($path, json_encode(['schemaVersion' => 1, 'label' => $badgeLabel, 'message' => $metric->formatValue($value) . '%', 'color' => $color], \JSON_THROW_ON_ERROR) . "\n");
    }

    /** @param list<QualityMetric> $metrics */
    public function renderMarkdown(array $metrics, ?self $baseline): string
    {
        $lines = $baseline === null
            ? ['| Metric | Value |', '| --- | ---: |']
            : ['| Metric | main | This change | Change |', '| --- | ---: | ---: | --- |'];

        foreach ($metrics as $metric) {
            $current = $this->values[$metric->key] ?? null;

            if ($current === null) {
                continue;
            }

            $previous = $baseline?->values[$metric->key] ?? null;

            $lines[] = $baseline === null
                ? sprintf('| %s | %s |', $metric->label, $metric->formatValue($current))
                : sprintf(
                    '| %s | %s | %s | %s |',
                    $metric->label,
                    $previous === null ? '—' : $metric->formatValue($previous),
                    $metric->formatValue($current),
                    $previous === null ? '' : $metric->formatChange($current, $previous),
                );
        }

        return "### Quality\n\n" . implode("\n", $lines) . "\n";
    }

    private static function readJsonFile(string $path): mixed
    {
        $contents = @file_get_contents($path);

        return $contents === false ? null : json_decode($contents, true);
    }

    private static function findNumber(mixed $node, string ...$path): ?float
    {
        foreach ($path as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return null;
            }

            $node = $node[$key];
        }

        return is_numeric($node) ? (float) $node : null;
    }
}

/** @var list<string> $argv */
[, $reportsDirectory, $baselineFile] = $argv + [1 => 'var/reports', 2 => ''];

$lineCoverage = new QualityMetric('coverage_lines', 'Line coverage %', true, 2);
$coveredMsi = new QualityMetric('covered_msi', 'Covered code MSI %', true, 2);
$metrics = [
    $lineCoverage,
    new QualityMetric('coverage_methods', 'Method coverage %', true, 2),
    $coveredMsi,
    new QualityMetric('classes', 'Classes', null, 0),
    new QualityMetric('logical_lines', 'Logical lines of code', null, 0),
    new QualityMetric('cyclomatic_complexity', 'Average cyclomatic complexity per class', false, 2),
    new QualityMetric('violation_errors', 'phpmetrics errors', false, 0),
    new QualityMetric('violation_warnings', 'phpmetrics warnings', false, 0),
];

$snapshot = QualitySnapshot::readFromReports($reportsDirectory);
$snapshot->writeToSummaryFile($reportsDirectory . '/summary.json');
$snapshot->writeBadgeFile($reportsDirectory . '/badge-coverage.json', $lineCoverage, 'coverage');
$snapshot->writeBadgeFile($reportsDirectory . '/badge-msi.json', $coveredMsi, 'mutation MSI');
file_put_contents($reportsDirectory . '/summary.md', $snapshot->renderMarkdown($metrics, $baselineFile === '' ? null : QualitySnapshot::readFromSummaryFile($baselineFile)));
