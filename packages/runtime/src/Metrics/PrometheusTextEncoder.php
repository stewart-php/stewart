<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics;

use Stewart\Runtime\Metrics\Exposition\Collection\MetricFamilyCollection;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\Exposition\MetricSample;
use Stewart\Runtime\Metrics\Exposition\PrometheusNumber;

final readonly class PrometheusTextEncoder
{
    public const string CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    private const array HELP_ESCAPES = ['\\' => '\\\\', "\n" => '\\n'];

    private const array LABEL_VALUE_ESCAPES = ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n'];

    public function encodeFamilies(MetricFamilyCollection $families): string
    {
        $text = '';

        foreach ($families as $family) {
            if (!$family->samples->isEmpty()) {
                $text .= $this->encodeFamily($family);
            }
        }

        return $text;
    }

    private function encodeFamily(MetricFamily $family): string
    {
        $text = \sprintf("# HELP %s %s\n# TYPE %s %s\n", $family->name, strtr($family->help, self::HELP_ESCAPES), $family->name, $family->type->value);

        foreach ($family->samples as $sample) {
            $text .= $this->encodeSample($family->name, $sample);
        }

        return $text;
    }

    private function encodeSample(string $familyName, MetricSample $sample): string
    {
        return \sprintf("%s%s%s %s\n", $familyName, $sample->suffix->value, $this->encodeLabels($sample->labels), PrometheusNumber::formatValue($sample->value));
    }

    private function encodeLabels(MetricLabels $labels): string
    {
        $pairs = [];

        foreach ($labels->listValuesByName() as $name => $value) {
            $pairs[] = \sprintf('%s="%s"', $name, strtr($value, self::LABEL_VALUE_ESCAPES));
        }

        return $pairs === [] ? '' : '{' . implode(',', $pairs) . '}';
    }
}
