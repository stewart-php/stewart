<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Metrics\Source;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Metrics\PrometheusTextEncoder;
use Stewart\Runtime\Metrics\Source\DeployMetricSource;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;

#[CoversClass(DeployMetricSource::class)]
final class DeployMetricSourceTest extends TestCase
{
    public function testRunningCommitAndFailuresAreExported(): void
    {
        $text = new PrometheusTextEncoder()->encodeFamilies(new DeployMetricSource()->collectMetrics(new StubSnapshotSource()->takeSnapshot()));

        self::assertStringContainsString('stewart_deploy_info{commit="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"} 1', $text);
        self::assertStringContainsString('stewart_deploy_failures_total 1', $text);
    }

    public function testNothingIsExportedWithoutPolling(): void
    {
        $stub = new StubSnapshotSource()->takeSnapshot();
        $snapshot = new RuntimeSnapshot($stub->takenAt, $stub->daemon, $stub->connection, $stub->broker, [], [], []);
        $text = new PrometheusTextEncoder()->encodeFamilies(new DeployMetricSource()->collectMetrics($snapshot));

        self::assertStringNotContainsString('stewart_deploy_info{', $text);
        self::assertStringNotContainsString('stewart_deploy_failures_total 0', $text);
    }
}
