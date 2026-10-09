<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Metrics\Source;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Control\Protocol\Status\ComponentStatus;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Runtime\Metrics\PrometheusTextEncoder;
use Stewart\Runtime\Metrics\Source\ComponentMetricSource;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;

#[CoversClass(ComponentMetricSource::class)]
final class ComponentMetricSourceTest extends TestCase
{
    public function testActiveComponentExportsVersionAndState(): void
    {
        $text = new PrometheusTextEncoder()->encodeFamilies(new ComponentMetricSource()->collectMetrics(new StubSnapshotSource()->takeSnapshot()));

        self::assertStringContainsString('stewart_component_info{version="0.9.0",protocol="1"} 1', $text);
        self::assertStringContainsString('stewart_component_state{state="active"} 1', $text);
        self::assertStringContainsString('stewart_component_state{state="missing"} 0', $text);
    }

    public function testMissingComponentHasNoInfo(): void
    {
        $stub = new StubSnapshotSource()->takeSnapshot();
        $component = new ComponentStatus(ComponentState::Missing, $stub->takenAt, 'default');
        $snapshot = new RuntimeSnapshot($stub->takenAt, $stub->daemon, $stub->connection, $stub->broker, [], [], [], component: $component);
        $text = new PrometheusTextEncoder()->encodeFamilies(new ComponentMetricSource()->collectMetrics($snapshot));

        self::assertStringNotContainsString('stewart_component_info{', $text);
        self::assertStringContainsString('stewart_component_state{state="missing"} 1', $text);
    }
}
