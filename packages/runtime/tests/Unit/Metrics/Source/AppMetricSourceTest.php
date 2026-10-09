<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Metrics\Source;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Control\Protocol\Status\AppCounters;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Control\Protocol\Status\ExposedCommandStats;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Metrics\PrometheusTextEncoder;
use Stewart\Runtime\Metrics\Source\AppMetricSource;
use Stewart\Runtime\Model\ExposedCommandOutcome;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;

#[CoversClass(AppMetricSource::class)]
final class AppMetricSourceTest extends TestCase
{
    public function testSharedScopeKeepsCountersButIsNoApp(): void
    {
        $stub = new StubSnapshotSource()->takeSnapshot();
        $shared = new AppStatus(ResourceScope::shared()->wireValue(), '', 1, null, null, null, null, 0, 0, 0, new AppCounters(publishes: 3), [], null);
        $snapshot = new RuntimeSnapshot($stub->takenAt, $stub->daemon, $stub->connection, $stub->broker, [], [$shared], []);

        $text = new PrometheusTextEncoder()->encodeFamilies(new AppMetricSource()->collectMetrics($snapshot));

        self::assertStringContainsString('stewart_app_publishes_total{app="@shared",worker="1"} 3', $text);
        self::assertStringNotContainsString('stewart_app_info', $text);
        self::assertStringNotContainsString('stewart_app_state', $text);
        self::assertStringNotContainsString('stewart_app_paused', $text);
    }

    public function testExposedCommandsAreCountedByOutcome(): void
    {
        $stub = new StubSnapshotSource()->takeSnapshot();
        $app = new AppStatus('heater', 'Heater', 0, null, null, null, null, 0, 0, 1, new AppCounters(), [], null, [
            new ExposedCommandStats(ExposedCommandOutcome::Accepted, 4),
            new ExposedCommandStats(ExposedCommandOutcome::Lost, 1),
        ]);
        $snapshot = new RuntimeSnapshot($stub->takenAt, $stub->daemon, $stub->connection, $stub->broker, [], [$app], []);

        $text = new PrometheusTextEncoder()->encodeFamilies(new AppMetricSource()->collectMetrics($snapshot));

        self::assertStringContainsString('stewart_app_exposed_commands_total{app="heater",worker="0",outcome="accepted"} 4', $text);
        self::assertStringContainsString('stewart_app_exposed_commands_total{app="heater",worker="0",outcome="lost"} 1', $text);
    }
}
