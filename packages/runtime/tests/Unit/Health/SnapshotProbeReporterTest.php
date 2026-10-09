<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Component\ComponentInstance;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\Component\ComponentTracker;
use Stewart\Runtime\Broker\ConnectionTracker;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\Deploy\DeployState;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Control\Assembler\BrokerStatsBuilder;
use Stewart\Runtime\Control\Assembler\ComponentStatusBuilder;
use Stewart\Runtime\Control\Assembler\DaemonInfoBuilder;
use Stewart\Runtime\Control\Assembler\DeployStatusBuilder;
use Stewart\Runtime\Control\Assembler\RegistrationInfoBuilder;
use Stewart\Runtime\Control\Assembler\StoreHealthBuilder;
use Stewart\Runtime\Control\Assembler\WorkerStatusBuilder;
use Stewart\Runtime\Control\SnapshotAssembler;
use Stewart\Runtime\Health\ProbeKind;
use Stewart\Runtime\Health\ProbeReport;
use Stewart\Runtime\Health\ProbeStatus;
use Stewart\Runtime\Health\ReadinessCheck;
use Stewart\Runtime\Health\SnapshotProbeReporter;
use Stewart\Runtime\Tests\Fixtures\Broker\ExposureLinkFixture;
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

    public function testReadinessIsReadyOnceConnected(): void
    {
        $this->broker->startWorker(0);
        $this->connection->markConnected();

        self::assertEquals(new ProbeReport(ProbeStatus::Ready, []), $this->createReporter()->reportProbe(ProbeKind::Readiness));
    }

    private function createReporter(): SnapshotProbeReporter
    {
        $broker = $this->broker;
        $startTime = new DaemonStartTime($broker->timers->clock);
        $startTime->recordStart();

        $snapshots = new SnapshotAssembler(
            daemonInfo: new DaemonInfoBuilder($broker->session, '0.1.0-test', $startTime),
            brokerStats: new BrokerStatsBuilder($broker->pools->slots, $broker->callSlots, $broker->registry),
            workerStatuses: new WorkerStatusBuilder($broker->pools->slots, $broker->pools->watchdog, $broker->pools->restartPolicy, $broker->callSlots),
            registrationInfos: new RegistrationInfoBuilder($broker->registry),
            storeHealth: new StoreHealthBuilder($broker->pools->slots, storeConfigured: false),
            appStatuses: new AppStatusBuilder($broker->metrics, new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]), $startTime), ExposureLinkFixture::createWithoutExposures()),
            deployStatus: new DeployStatusBuilder(new DeployState()),
            componentStatus: new ComponentStatusBuilder(new ComponentTracker($broker->timers->clock), new ExposeConfig(ComponentInstance::parse('default'), Duration::seconds(10))),
            connection: $this->connection,
            clock: $broker->timers->clock,
        );

        return new SnapshotProbeReporter($snapshots, new ReadinessCheck());
    }
}
