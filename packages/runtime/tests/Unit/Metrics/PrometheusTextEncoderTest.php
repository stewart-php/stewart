<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Metrics\Exposition\Collection\MetricFamilyCollection;
use Stewart\Runtime\Metrics\Exposition\HistogramBuckets;
use Stewart\Runtime\Metrics\Exposition\MetricFamily;
use Stewart\Runtime\Metrics\Exposition\MetricLabels;
use Stewart\Runtime\Metrics\PrometheusTextEncoder;

#[CoversClass(PrometheusTextEncoder::class)]
#[CoversClass(MetricFamily::class)]
#[CoversClass(MetricLabels::class)]
final class PrometheusTextEncoderTest extends TestCase
{
    public function testWritesHelpTypeAndLabelledSamples(): void
    {
        $family = MetricFamily::createCounter('stewart_things_total', 'Things.')
            ->withSample(MetricLabels::withSingleLabel('app', 'porch')->withLabel('worker', '0'), 3)
            ->withSample(MetricLabels::none(), 0.25);

        self::assertSame(
            "# HELP stewart_things_total Things.\n# TYPE stewart_things_total counter\nstewart_things_total{app=\"porch\",worker=\"0\"} 3\nstewart_things_total 0.25\n",
            $this->encode($family),
        );
    }

    public function testEscapesLabelValuesAndHelp(): void
    {
        $family = MetricFamily::createGauge('stewart_info', "Back\\slash\nnewline")
            ->withSample(MetricLabels::withSingleLabel('class', "App\\\"Quoted\"\n"), true);

        self::assertSame(
            "# HELP stewart_info Back\\\\slash\\nnewline\n# TYPE stewart_info gauge\nstewart_info{class=\"App\\\\\\\"Quoted\\\"\\n\"} 1\n",
            $this->encode($family),
        );
    }

    public function testHistogramWritesBucketsSumAndCount(): void
    {
        $family = MetricFamily::createHistogram('stewart_call_seconds', 'Calls.')
            ->withHistogram(MetricLabels::withSingleLabel('outcome', 'ok'), new HistogramBuckets([0.005, 0.25, INF], [1, 3, 4], 4, Duration::milliseconds(1500)));

        self::assertSame(
            "# HELP stewart_call_seconds Calls.\n# TYPE stewart_call_seconds histogram\n"
            . "stewart_call_seconds_bucket{outcome=\"ok\",le=\"0.005\"} 1\n"
            . "stewart_call_seconds_bucket{outcome=\"ok\",le=\"0.25\"} 3\n"
            . "stewart_call_seconds_bucket{outcome=\"ok\",le=\"+Inf\"} 4\n"
            . "stewart_call_seconds_sum{outcome=\"ok\"} 1.5\n"
            . "stewart_call_seconds_count{outcome=\"ok\"} 4\n",
            $this->encode($family),
        );
    }

    public function testFamilyWithoutSamplesIsLeftOut(): void
    {
        self::assertSame('', $this->encode(MetricFamily::createGauge('stewart_empty', 'Nothing.')));
    }

    private function encode(MetricFamily $family): string
    {
        return new PrometheusTextEncoder()->encodeFamilies(MetricFamilyCollection::fromFamilies([$family]));
    }
}
