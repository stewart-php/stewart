<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Health\ReadinessCheck;
use Stewart\Runtime\Health\ReadinessVerdict;
use Stewart\Runtime\Lifecycle\ConnectionPhase;
use Stewart\Runtime\Lifecycle\WorkerPhase;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;

#[CoversClass(ReadinessCheck::class)]
#[CoversClass(ReadinessVerdict::class)]
final class ReadinessCheckTest extends TestCase
{
    public function testConnectedDaemonWithLiveWorkersIsReady(): void
    {
        $verdict = new ReadinessCheck()->assessSnapshot(new StubSnapshotSource()->takeSnapshot());

        self::assertTrue($verdict->isReady());
        self::assertSame('ready', $verdict->describeVerdict());
    }

    public function testConnectingDaemonIsNotReady(): void
    {
        $verdict = new ReadinessCheck()->assessSnapshot(new StubSnapshotSource(connectionPhase: ConnectionPhase::Connecting)->takeSnapshot());

        self::assertFalse($verdict->isReady());
        self::assertSame(['Home Assistant is connecting'], $verdict->unreadyReasons);
    }

    public function testQuarantinedWorkerIsNotReady(): void
    {
        $verdict = new ReadinessCheck()->assessSnapshot(new StubSnapshotSource(workerPhases: [WorkerPhase::Live, WorkerPhase::Quarantined])->takeSnapshot());

        self::assertSame('not ready: worker 1 is quarantined', $verdict->describeVerdict());
    }

    public function testRestartingWorkerStaysReadyWhileAnotherRuns(): void
    {
        $verdict = new ReadinessCheck()->assessSnapshot(new StubSnapshotSource(workerPhases: [WorkerPhase::Live, WorkerPhase::RestartScheduled])->takeSnapshot());

        self::assertTrue($verdict->isReady());
    }

    public function testNoRunningWorkerIsNotReady(): void
    {
        $verdict = new ReadinessCheck()->assessSnapshot(new StubSnapshotSource(workerPhases: [WorkerPhase::RestartScheduled, WorkerPhase::Spawning])->takeSnapshot());

        self::assertSame(['no worker is running'], $verdict->unreadyReasons);
    }

    public function testWorkerStillStartingItsAppsIsNotReady(): void
    {
        $verdict = new ReadinessCheck()->assessSnapshot(new StubSnapshotSource(workersReady: false)->takeSnapshot());

        self::assertSame(['no worker is running'], $verdict->unreadyReasons);
    }

    public function testLastWorkerQuarantinedGivesBothReasons(): void
    {
        $verdict = new ReadinessCheck()->assessSnapshot(new StubSnapshotSource(workerPhases: [WorkerPhase::Quarantined])->takeSnapshot());

        self::assertSame(['no worker is running', 'worker 0 is quarantined'], $verdict->unreadyReasons);
    }
}
