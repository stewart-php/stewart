<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerPool;
use Stewart\Runtime\Broker\WorkerProbeSequence;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Broker\WorkerWatchdog;
use Stewart\Runtime\Config\SupervisionConfig;
use Stewart\Runtime\Ipc\Message\Ping;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerSpawner;
use Stewart\Runtime\Tests\Fixtures\Broker\RecordingPoolListener;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(WorkerWatchdog::class)]
#[CoversClass(WorkerHandle::class)]
#[CoversClass(WorkerProbeSequence::class)]
final class WorkerWatchdogTest extends TestCase
{
    private FakeWorkerSpawner $spawner;

    private ManualTimers $timers;

    private RecordingLogger $logger;

    private WorkerPool $pool;

    private WorkerWatchdog $watchdog;

    private WorkerSlotRegistry $slots;

    protected function setUp(): void
    {
        $this->spawner = new FakeWorkerSpawner();
        $this->timers = new ManualTimers();
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        $this->pool->shutdown('test over', Duration::zero());
    }

    public function testUnresponsiveWorkerIsRestarted(): void
    {
        $this->watch(ConfigFixture::createSupervisionConfig(['restart_initial_delay' => '1s', 'restart_max_delay' => '1s', 'ping_interval' => 'off', 'unresponsive_after' => 2]));
        $first = $this->spawner->getLatestProcess(0);

        $this->watchdog->probeWorkers();
        $this->watchdog->probeWorkers();
        self::assertFalse($first->closed, 'One missed probe is only a warning.');

        $this->watchdog->probeWorkers();
        EventLoopTicks::settle();
        $this->timers->delay(Duration::seconds(1));

        self::assertTrue($first->closed);
        self::assertSame(2, $this->spawner->countSpawnedProcesses());
        self::assertContains('Worker stopped answering liveness probes; killing it', $this->logger->listMessagesAt('error'));
    }

    public function testWorkerThatAnswersIsLeftAlone(): void
    {
        $this->watch(ConfigFixture::createSupervisionConfig(['ping_interval' => 'off', 'unresponsive_after' => 1]));
        $handle = $this->getHandle();

        for ($round = 0; $round < 5; ++$round) {
            $this->watchdog->probeWorkers();
            $this->watchdog->recordPong($handle, new Pong($this->getLastPing()->nonce, Duration::zero(), 0));
        }

        self::assertFalse($this->spawner->getLatestProcess(0)->closed);
        self::assertSame(1, $this->spawner->countSpawnedProcesses());
        self::assertSame(0, $this->watchdog->countMissedProbes($handle));
    }

    public function testQuietWorkerReportedOnFirstAndEverySixthMiss(): void
    {
        $this->watch(ConfigFixture::createSupervisionConfig(['ping_interval' => 'off', 'unresponsive_after' => 0]));

        for ($probe = 0; $probe < 14; ++$probe) {
            $this->watchdog->probeWorkers();
        }

        self::assertCount(3, $this->logger->listMessagesAt('warning'), 'Missed 1, 6 and 12.');
        self::assertFalse($this->spawner->getLatestProcess(0)->closed, 'Killing is off.');
        self::assertSame(14, $this->watchdog->countMissedProbes($this->getHandle()));
    }

    public function testLateWorkerOwesNoEarlierProbes(): void
    {
        $this->watch(ConfigFixture::createSupervisionConfig(['ping_interval' => 'off']));
        $this->watchdog->probeWorkers();
        $this->watchdog->probeWorkers();

        $this->pool->startWorker(self::createSlot(1), new RecordingPoolListener());
        $late = $this->slots->findHandleForWorker(new WorkerId(1));
        self::assertNotNull($late);

        self::assertSame(0, $this->watchdog->countMissedProbes($late));

        $this->watchdog->probeWorkers();

        self::assertSame(1, $this->watchdog->countMissedProbes($late), 'Only the probe sent since it started is outstanding.');
        self::assertSame(3, $this->watchdog->countMissedProbes($this->getHandle()));
    }

    public function testPongIsRecordedAndALaggingLoopIsReported(): void
    {
        $this->watch(ConfigFixture::createSupervisionConfig(['ping_interval' => 'off', 'lag_threshold' => '100ms']));
        $handle = $this->getHandle();

        $this->watchdog->recordPong($handle, new Pong(0, Duration::milliseconds(50), 1_000));
        self::assertSame([], $this->logger->listMessagesAt('warning'));

        $this->timers->delay(Duration::seconds(3));
        $this->watchdog->recordPong($handle, new Pong(0, Duration::milliseconds(250), 2_000));

        self::assertSame(2_000, $handle->lastPong?->memoryBytes);
        self::assertEquals($this->timers->clock->getNow(), $handle->lastPongAt);
        self::assertSame(['Worker event loop is lagging; is an app blocking?'], $this->logger->listMessagesAt('warning'));
    }

    public function testIntervalDrivesTheProbesUntilStopped(): void
    {
        $this->watch(ConfigFixture::createSupervisionConfig(['ping_interval' => '10s']));

        for ($tick = 0; $tick < 3; ++$tick) {
            $this->timers->delay(Duration::seconds(10));
            EventLoopTicks::settle();
        }

        self::assertCount(3, $this->spawner->getLatestProcess(0)->channel->listSentOfType(Ping::class));

        $this->watchdog->stopProbing();
        $this->timers->delay(Duration::seconds(30));
        EventLoopTicks::settle();

        self::assertCount(3, $this->spawner->getLatestProcess(0)->channel->listSentOfType(Ping::class));
        self::assertSame(1, $this->timers->countPendingTimers(), 'Only the readiness deadline is left.');
    }

    public function testNoTimerRunsWhenProbingIsOff(): void
    {
        $this->watch(ConfigFixture::createSupervisionConfig(['ping_interval' => 'off', 'ready_timeout' => 'off']));

        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testStoppedProbesAndPoolLeaveNoTimers(): void
    {
        $this->watch(ConfigFixture::createSupervisionConfig(['ping_interval' => '10s']));

        $this->watchdog->stopProbing();
        $this->pool->shutdown('test over', Duration::zero());
        $this->timers->delay(Duration::minutes(1));
        EventLoopTicks::settle();

        self::assertSame([], $this->spawner->getLatestProcess(0)->channel->listSentOfType(Ping::class));
        self::assertSame(0, $this->timers->countPendingTimers());
    }

    private function watch(SupervisionConfig $supervision): void
    {
        $pools = WorkerPoolFixture::createWorkerPool($this->spawner, $supervision, $this->timers, $this->logger);
        $this->pool = $pools->pool;
        $this->watchdog = $pools->watchdog;
        $this->slots = $pools->slots;

        $this->pool->startWorker(self::createSlot(0), new RecordingPoolListener());
        $this->watchdog->startProbing();
    }

    private function getHandle(): WorkerHandle
    {
        $handle = $this->slots->findHandleForWorker(new WorkerId(0));
        self::assertNotNull($handle);

        return $handle;
    }

    private function getLastPing(): Ping
    {
        EventLoopTicks::settle();
        $pings = $this->spawner->getLatestProcess(0)->channel->listSentOfType(Ping::class);

        return $pings[\count($pings) - 1] ?? self::fail('No ping was sent.');
    }

    private static function createSlot(int $workerId): WorkerSlot
    {
        return new WorkerSlot(new WorkerId($workerId), AppDefinitionCollection::keyedByAppId([new AppDefinition(id: new AppId('demo'), class: Demo::class)]));
    }
}
