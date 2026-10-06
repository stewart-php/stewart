<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Metrics\Source;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Control\Protocol\Status\WorkerStatus;
use Stewart\Runtime\Lifecycle\WorkerPhase;
use Stewart\Runtime\Metrics\PrometheusTextEncoder;
use Stewart\Runtime\Metrics\Source\WorkerMetricSource;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;

#[CoversClass(WorkerMetricSource::class)]
final class WorkerMetricSourceTest extends TestCase
{
    public function testMissingReadingsAreLeftOutNotZeroed(): void
    {
        $stub = new StubSnapshotSource()->takeSnapshot();
        $snapshot = new RuntimeSnapshot($stub->takenAt, $stub->daemon, $stub->connection, $stub->broker, [new WorkerStatus(1, WorkerPhase::RestartScheduled, ['echo'])], [], []);
        $text = new PrometheusTextEncoder()->encodeFamilies(new WorkerMetricSource()->collectMetrics($snapshot));

        self::assertStringContainsString('stewart_worker_phase{worker="1",phase="restart_scheduled"} 1', $text);
        self::assertStringNotContainsString('stewart_worker_loop_lag_seconds', $text);
        self::assertStringNotContainsString('stewart_worker_memory_bytes', $text);
        self::assertStringNotContainsString('stewart_worker_outbox_queued', $text);
    }
}
