<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\BrokerRun;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\Mqtt\DisabledMqttLink;
use Stewart\Runtime\Broker\SignalHandlers;
use Stewart\Runtime\Broker\WorkerPool;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerSpawner;
use Stewart\Runtime\Tests\Fixtures\Broker\RecordingPoolListener;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(SignalHandlers::class)]
#[CoversClass(BrokerRun::class)]
final class SignalHandlersTest extends TestCase
{
    private RecordingLogger $logger;

    private FakeWorkerSpawner $spawner;

    private WorkerPool $pool;

    private BrokerRun $run;

    private SignalHandlers $signals;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->spawner = new FakeWorkerSpawner(joinLatch: new Latch());
        $pools = WorkerPoolFixture::createWorkerPool($this->spawner, timers: new ManualTimers(), logger: $this->logger);
        $this->pool = $pools->pool;
        $this->run = new BrokerRun($pools->pool, $pools->watchdog, FakeHaSession::createOpened(), new DisabledMqttLink($this->logger), $this->logger, Duration::minutes(1), new DaemonStartTime($pools->clock));
        $this->run->start();

        $this->signals = new SignalHandlers($this->run, $this->logger);
    }

    protected function tearDown(): void
    {
        $this->signals->removeAll();
    }

    public function testSigtermStopsTheRun(): void
    {
        $this->installSignals();

        posix_kill(posix_getpid(), \SIGTERM);
        EventLoopTicks::settleUntil(fn(): bool => \count($this->logger->listMessagesAt('info')) === 1);

        self::assertSame(['Shutting down'], $this->logger->listMessagesAt('info'));
        self::assertSame('signal', $this->logger->records->getFirst()?->context['reason']);
    }

    public function testSecondSignalKillsWorkersWithoutGrace(): void
    {
        $this->pool->startWorker(new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])), new RecordingPoolListener());
        $this->installSignals();

        posix_kill(posix_getpid(), \SIGTERM);
        EventLoopTicks::settleUntil(fn(): bool => $this->run->isStopping());
        posix_kill(posix_getpid(), \SIGTERM);
        EventLoopTicks::settleUntil(fn(): bool => !$this->run->isStopping());

        self::assertTrue($this->spawner->getLatestProcess(0)->closed);
        self::assertSame(['Killing workers without waiting for their grace'], $this->logger->listMessagesAt('warning'));
    }

    public function testRemovingTwiceIsHarmless(): void
    {
        $this->signals->install();
        $this->signals->removeAll();
        $this->signals->removeAll();

        $this->addToAssertionCount(1);
    }

    private function installSignals(): void
    {
        $this->signals->install();
        // Revolt arms a signal watcher on the next tick; a signal sent before that would kill the test run.
        EventLoopTicks::settle();
    }
}
