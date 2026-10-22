<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Metrics\PrometheusTextEncoder;
use Stewart\Runtime\Metrics\RuntimeMetricsCollector;
use Stewart\Runtime\Metrics\Source\AppMetricSource;
use Stewart\Runtime\Metrics\Source\BrokerMetricSource;
use Stewart\Runtime\Metrics\Source\ComponentMetricSource;
use Stewart\Runtime\Metrics\Source\ConnectionMetricSource;
use Stewart\Runtime\Metrics\Source\DaemonMetricSource;
use Stewart\Runtime\Metrics\Source\DeployMetricSource;
use Stewart\Runtime\Metrics\Source\GeneratedCodeMetricSource;
use Stewart\Runtime\Metrics\Source\MqttMetricSource;
use Stewart\Runtime\Metrics\Source\StoreMetricSource;
use Stewart\Runtime\Metrics\Source\WorkerMetricSource;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;

#[CoversClass(RuntimeMetricsCollector::class)]
#[CoversClass(AppMetricSource::class)]
#[CoversClass(BrokerMetricSource::class)]
#[CoversClass(ComponentMetricSource::class)]
#[CoversClass(ConnectionMetricSource::class)]
#[CoversClass(DaemonMetricSource::class)]
#[CoversClass(DeployMetricSource::class)]
#[CoversClass(GeneratedCodeMetricSource::class)]
#[CoversClass(MqttMetricSource::class)]
#[CoversClass(StoreMetricSource::class)]
#[CoversClass(WorkerMetricSource::class)]
// Set UPDATE_GOLDEN=1 to rewrite tests/Fixtures/Metrics/runtime-metrics.txt from the stub snapshot.
final class RuntimeMetricsCollectorTest extends TestCase
{
    private const string GOLDEN = __DIR__ . '/../../Fixtures/Metrics/runtime-metrics.txt';

    private const string BUCKET_BOUND_LABEL = 'le';

    public function testEverySnapshotSectionBecomesMetrics(): void
    {
        $collector = new RuntimeMetricsCollector([
            new DaemonMetricSource(),
            new ConnectionMetricSource(),
            new BrokerMetricSource(),
            new WorkerMetricSource(),
            new AppMetricSource(),
            new StoreMetricSource(),
            new MqttMetricSource(),
            new GeneratedCodeMetricSource(),
        ]);

        $text = new PrometheusTextEncoder()->encodeFamilies($collector->collectMetrics(new StubSnapshotSource()->takeSnapshot()));

        if (getenv('UPDATE_GOLDEN') === '1') {
            file_put_contents(self::GOLDEN, $text);
        }

        self::assertStringEqualsFile(self::GOLDEN, $text);
    }

    public function testSamplesCarryTheDeclaredLabels(): void
    {
        $collector = new RuntimeMetricsCollector([
            new DaemonMetricSource(),
            new ConnectionMetricSource(),
            new BrokerMetricSource(),
            new WorkerMetricSource(),
            new AppMetricSource(),
            new StoreMetricSource(),
            new ComponentMetricSource(),
            new DeployMetricSource(),
            new MqttMetricSource(),
            new GeneratedCodeMetricSource(),
        ]);

        foreach ($collector->collectMetrics(new StubSnapshotSource()->takeSnapshot()) as $family) {
            foreach ($family->listSamples() as $sample) {
                $labelNames = array_keys(array_diff_key($sample->labels->listValuesByName(), [self::BUCKET_BOUND_LABEL => true]));

                self::assertSame($family->labelNames, $labelNames, $family->name);
            }
        }
    }
}
