<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Metrics\PrometheusTextEncoder;
use Stewart\Runtime\Metrics\RuntimeMetricsCollector;
use Stewart\Runtime\Metrics\Source\AppMetricSource;
use Stewart\Runtime\Metrics\Source\BrokerMetricSource;
use Stewart\Runtime\Metrics\Source\ConnectionMetricSource;
use Stewart\Runtime\Metrics\Source\DaemonMetricSource;
use Stewart\Runtime\Metrics\Source\StoreMetricSource;
use Stewart\Runtime\Metrics\Source\WorkerMetricSource;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;

#[CoversClass(RuntimeMetricsCollector::class)]
#[CoversClass(AppMetricSource::class)]
#[CoversClass(BrokerMetricSource::class)]
#[CoversClass(ConnectionMetricSource::class)]
#[CoversClass(DaemonMetricSource::class)]
#[CoversClass(StoreMetricSource::class)]
#[CoversClass(WorkerMetricSource::class)]
// Set UPDATE_GOLDEN=1 to rewrite tests/Fixtures/Metrics/runtime-metrics.txt from the stub snapshot.
final class RuntimeMetricsCollectorTest extends TestCase
{
    private const string GOLDEN = __DIR__ . '/../../Fixtures/Metrics/runtime-metrics.txt';

    public function testEverySnapshotSectionBecomesMetrics(): void
    {
        $collector = new RuntimeMetricsCollector([
            new DaemonMetricSource(),
            new ConnectionMetricSource(),
            new BrokerMetricSource(),
            new WorkerMetricSource(),
            new AppMetricSource(),
            new StoreMetricSource(),
        ]);

        $text = new PrometheusTextEncoder()->encodeFamilies($collector->collectMetrics(new StubSnapshotSource()->takeSnapshot()));

        if (getenv('UPDATE_GOLDEN') === '1') {
            file_put_contents(self::GOLDEN, $text);
        }

        self::assertStringEqualsFile(self::GOLDEN, $text);
    }
}
