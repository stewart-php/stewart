<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control\Assembler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Control\Assembler\WorkerStatusBuilder;
use Stewart\Runtime\Control\Protocol\Status\WorkerStatus;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Lifecycle\WorkerPhase;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerSpawner;
use Stewart\Runtime\Tests\Fixtures\Control\BrokerStateFixture;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Time\EventLoopTicks;

use function Amp\async;

#[CoversClass(WorkerStatusBuilder::class)]
#[CoversClass(WorkerStatus::class)]
final class WorkerStatusBuilderTest extends TestCase
{
    private BrokerStateFixture $broker;

    protected function setUp(): void
    {
        $this->broker = new BrokerStateFixture();
    }

    protected function tearDown(): void
    {
        $this->broker->stopEverything();
    }

    public function testPoolThatStartedNothingHasNoWorkers(): void
    {
        self::assertSame([], $this->createBuilder()->buildWorkerStatuses()->listValues());
    }

    public function testDescribesLiveWorkerFromBrokerState(): void
    {
        $this->broker->startWorker(0);
        $handle = $this->broker->getLiveHandleOf(0);

        $before = $this->buildStatusOf(0);
        self::assertSame(WorkerPhase::Live, $before->phase);
        self::assertSame(['demo'], $before->appIds);
        self::assertSame(4242, $before->pid);
        self::assertFalse($before->ready);
        self::assertNull($before->loopLag);
        self::assertNull($before->lastPongAt);
        self::assertSame(0, $before->missedProbes);
        self::assertSame(0, $before->restartsInWindow);
        self::assertNull($before->restartDueAt);

        $this->broker->getPool()->markReady($handle);
        $this->broker->pools->watchdog->recordPong($handle, new Pong(nonce: 0, loopLag: Duration::milliseconds(12.5), memoryBytes: 4_000_000));

        $after = $this->buildStatusOf(0);
        self::assertTrue($after->ready);
        self::assertEquals(Duration::milliseconds(12.5), $after->loopLag);
        self::assertSame(4_000_000, $after->memoryBytes);
        self::assertEquals($this->broker->timers->clock->getNow(), $after->lastPongAt);
        self::assertNotNull($after->outbox);
        self::assertSame(0, $after->outbox->queued, 'Bootstrap is the listener\'s to send; this one sends nothing.');
    }

    public function testMissedProbesAreCountedFromTheWatchdog(): void
    {
        $this->broker->pools->watchdog->startProbing();
        $this->broker->startWorker(0);

        $this->broker->pools->watchdog->probeWorkers();
        $this->broker->pools->watchdog->probeWorkers();

        self::assertSame(2, $this->buildStatusOf(0)->missedProbes);
    }

    public function testInFlightServiceCallsBelongToTheLiveWorker(): void
    {
        $this->broker->startWorker(0);

        $this->broker->serviceCalls->forward($this->broker->getLiveHandleOf(0), new ServiceCallRequest(new CorrelationId('0:1'), ResourceScope::forApp(new AppId('demo')), 'light', 'turn_on', [], null, false));

        self::assertSame(1, $this->buildStatusOf(0)->inFlightServiceCalls);

        $this->broker->crashWorker(0);

        self::assertSame(0, $this->buildStatusOf(0)->inFlightServiceCalls, 'A dead worker has nothing in flight of its own.');
    }

    public function testRestartOnItsWaySaysWhenItIsDue(): void
    {
        $this->broker->startWorker(0);

        $this->broker->crashWorker(0);

        $status = $this->buildStatusOf(0);
        self::assertSame(WorkerPhase::RestartScheduled, $status->phase);
        self::assertSame(1, $status->restartsInWindow);
        self::assertEquals($this->broker->timers->clock->getNow()->plus(Duration::seconds(BrokerStateFixture::RESTART_DELAY_SECONDS)), $status->restartDueAt);
        self::assertSame(['demo'], $status->appIds);
        self::assertNull($status->pid);

        $this->broker->getPool()->shutdown('test over', Duration::zero());

        self::assertSame(WorkerPhase::Stopped, $this->buildStatusOf(0)->phase);
        self::assertNull($this->buildStatusOf(0)->restartDueAt);
    }

    public function testQuarantinedWorkerListsItsApps(): void
    {
        $this->broker->startWorker(0);

        for ($crash = 0; $crash < 3; ++$crash) {
            $this->broker->crashWorker(0);
            $this->broker->timers->delay(Duration::seconds(BrokerStateFixture::RESTART_DELAY_SECONDS));
        }

        $status = $this->buildStatusOf(0);
        self::assertSame(WorkerPhase::Quarantined, $status->phase);
        self::assertSame(['demo'], $status->appIds);
        self::assertNull($status->pid);
        self::assertSame(2, $status->restartsInWindow);
    }

    public function testStartThatIsStillSpawningIsShownAsSpawning(): void
    {
        $spawning = new Latch();
        $this->broker->stopEverything();
        $this->broker = new BrokerStateFixture(new FakeWorkerSpawner(spawnLatch: $spawning));

        async(fn() => $this->broker->startWorker(0))->ignore();
        EventLoopTicks::settle();

        self::assertSame(WorkerPhase::Spawning, $this->buildStatusOf(0)->phase);

        $spawning->open();
        EventLoopTicks::settle();

        self::assertSame(WorkerPhase::Live, $this->buildStatusOf(0)->phase);
    }

    public function testWorkersAreListedInOrder(): void
    {
        $this->broker->startWorker(1);
        $this->broker->startWorker(0);

        self::assertSame([0, 1], array_map(static fn(WorkerStatus $status): int => $status->workerId, $this->createBuilder()->buildWorkerStatuses()->listValues()));
    }

    private function createBuilder(): WorkerStatusBuilder
    {
        $broker = $this->broker;

        return new WorkerStatusBuilder($broker->pools->slots, $broker->pools->watchdog, $broker->pools->restartPolicy, $broker->callSlots);
    }

    private function buildStatusOf(int $workerId): WorkerStatus
    {
        foreach ($this->createBuilder()->buildWorkerStatuses() as $status) {
            if ($status->workerId === $workerId) {
                return $status;
            }
        }

        self::fail(\sprintf('Worker %d has no status.', $workerId));
    }
}
