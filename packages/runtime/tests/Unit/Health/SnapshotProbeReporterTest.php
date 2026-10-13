<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Broker\ConnectionTracker;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Health\ProbeKind;
use Stewart\Runtime\Health\ProbeReport;
use Stewart\Runtime\Health\ProbeStatus;
use Stewart\Runtime\Health\ReadinessCheck;
use Stewart\Runtime\Health\SnapshotProbeReporter;
use Stewart\Runtime\Tests\Fixtures\Control\BrokerStateFixture;

#[CoversClass(SnapshotProbeReporter::class)]
final class SnapshotProbeReporterTest extends TestCase
{
    private BrokerStateFixture $broker;

    private ConnectionTracker $connection;

    protected function setUp(): void
    {
        $this->broker = new BrokerStateFixture();
        $this->connection = new ConnectionTracker($this->broker->timers->clock);
    }

    protected function tearDown(): void
    {
        $this->broker->stopEverything();
    }

    public function testLivenessIsAliveWhileConnecting(): void
    {
        self::assertEquals(ProbeReport::alive(), $this->createReporter()->reportProbe(ProbeKind::Liveness));
    }

    public function testReadinessNamesConnectingHomeAssistant(): void
    {
        $report = $this->createReporter()->reportProbe(ProbeKind::Readiness);

        self::assertSame(ProbeStatus::NotReady, $report->status);
        self::assertSame(['Home Assistant is connecting'], $report->reasons);
    }

    public function testReadinessIsReadyOnceConnectedWithAReadyWorker(): void
    {
        $this->broker->startWorker(0);
        $this->broker->getPool()->markReady($this->broker->getLiveHandleOf(0));
        $this->connection->markConnected();

        self::assertEquals(new ProbeReport(ProbeStatus::Ready, []), $this->createReporter()->reportProbe(ProbeKind::Readiness));
    }

    public function testReadinessWaitsForAWorkerToReportReady(): void
    {
        $this->broker->startWorker(0);
        $this->connection->markConnected();

        self::assertSame(['no worker is running'], $this->createReporter()->reportProbe(ProbeKind::Readiness)->reasons);
    }

    private function createReporter(): SnapshotProbeReporter
    {
        $broker = $this->broker;
        $startTime = new DaemonStartTime($broker->timers->clock);
        $startTime->recordStart();

        $snapshots = $broker->createSnapshotAssembler($startTime, $this->connection);

        return new SnapshotProbeReporter($snapshots, new ReadinessCheck());
    }
}
