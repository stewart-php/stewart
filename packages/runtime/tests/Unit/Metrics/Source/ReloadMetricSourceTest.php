<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Metrics\Source;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Metrics\PrometheusTextEncoder;
use Stewart\Runtime\Metrics\Source\ReloadMetricSource;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;

#[CoversClass(ReloadMetricSource::class)]
final class ReloadMetricSourceTest extends TestCase
{
    public function testReloadsAreCountedByOutcome(): void
    {
        $text = new PrometheusTextEncoder()->encodeFamilies(new ReloadMetricSource()->collectMetrics(new StubSnapshotSource()->takeSnapshot()));

        self::assertStringContainsString('stewart_reloads_total{outcome="applied"} 2', $text);
        self::assertStringContainsString('stewart_reloads_total{outcome="partly_applied"} 1', $text);
        self::assertStringContainsString('stewart_reloads_total{outcome="failed"} 0', $text);
    }

    public function testEveryOutcomeStartsAtZero(): void
    {
        $stub = new StubSnapshotSource()->takeSnapshot();
        $snapshot = new RuntimeSnapshot($stub->takenAt, $stub->daemon, $stub->connection, $stub->broker, [], [], []);
        $text = new PrometheusTextEncoder()->encodeFamilies(new ReloadMetricSource()->collectMetrics($snapshot));

        self::assertStringContainsString('stewart_reloads_total{outcome="applied"} 0', $text);
        self::assertStringContainsString('stewart_reloads_total{outcome="failed"} 0', $text);
    }
}
