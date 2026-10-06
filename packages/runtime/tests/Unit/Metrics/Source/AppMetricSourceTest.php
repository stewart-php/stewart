<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Metrics\Source;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Control\Protocol\Status\AppCounters;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Metrics\PrometheusTextEncoder;
use Stewart\Runtime\Metrics\Source\AppMetricSource;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;

#[CoversClass(AppMetricSource::class)]
final class AppMetricSourceTest extends TestCase
{
    public function testSharedScopeKeepsCountersButIsNoApp(): void
    {
        $stub = new StubSnapshotSource()->takeSnapshot();
        $shared = new AppStatus(ResourceScope::shared()->wireValue(), '', 1, null, null, null, null, 0, 0, new AppCounters(publishes: 3), [], null);
        $snapshot = new RuntimeSnapshot($stub->takenAt, $stub->daemon, $stub->connection, $stub->broker, [], [$shared], []);

        $text = new PrometheusTextEncoder()->encodeFamilies(new AppMetricSource()->collectMetrics($snapshot));

        self::assertStringContainsString('stewart_app_publishes_total{app="@shared",worker="1"} 3', $text);
        self::assertStringNotContainsString('stewart_app_info', $text);
        self::assertStringNotContainsString('stewart_app_state', $text);
        self::assertStringNotContainsString('stewart_app_paused', $text);
    }
}
