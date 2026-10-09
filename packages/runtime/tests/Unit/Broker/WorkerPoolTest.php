<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\RestartDecision;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerPool;
use Stewart\Runtime\Broker\WorkerRestartPolicy;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Broker\WorkerSlotState;
use Stewart\Runtime\Config\SupervisionConfig;
use Stewart\Runtime\Exception\BrokerError;
use Stewart\Runtime\Exception\BrokerException;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Message\Unsubscribe;
use Stewart\Runtime\Lifecycle\WorkerPhase;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerSpawner;
use Stewart\Runtime\Tests\Fixtures\Broker\RecordingPoolListener;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Logging\RecordedLog;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

use function Amp\async;
use function Amp\delay;

#[CoversClass(WorkerPool::class)]
#[CoversClass(WorkerHandle::class)]
#[CoversClass(WorkerSlotRegistry::class)]
#[CoversClass(WorkerRestartPolicy::class)]
#[CoversClass(RestartDecision::class)]
final class WorkerPoolTest extends TestCase
{
    private const int RESTART_DELAY_SECONDS = 1;

    private FakeWorkerSpawner $spawner;

    private RecordingPoolListener $listener;

    private ManualTimers $timers;

    private WorkerSlotRegistry $slots;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->spawner = new FakeWorkerSpawner();
        $this->listener = new RecordingPoolListener();
        $this->timers = new ManualTimers();
        $this->logger = new RecordingLogger();
    }

    public function testCrashedWorkerIsRespawnedWithTheSameApps(): void
    {
        $pool = $this->createPool();
        $pool->startWorker(self::createSlot(0), $this->listener);

        $this->crashAndRestart(0);

        self::assertSame(2, $this->spawner->countSpawnedProcesses());
        self::assertSame(['0: closed the channel'], $this->listener->gone);
        self::assertSame(['demo'], $this->slots->findHandleForWorker(new WorkerId(0))?->getAppIds()->toStrings());
        self::assertSame(1, $this->slots->countSpawnedSlots());
        self::assertSame(WorkerPhase::Live, $this->getPhaseOf(0));
    }

    public function testCrashedProcessIsClosedButNotItsReplacement(): void
    {
        $pool = $this->createPool();
        $pool->startWorker(self::createSlot(0), $this->listener);
        $first = $this->getHandle(0);

        $this->crashAndRestart(0);

        self::assertTrue($first->isTerminated());
        self::assertTrue($this->spawner->spawned[0][0]->closed);
        self::assertFalse($this->spawner->spawned[0][1]->closed);
        self::assertNotSame($first, $this->slots->findHandleForWorker(new WorkerId(0)));
    }

    public function testWorkerGoneDuringShutdownIsNotReported(): void
    {
        $this->spawner = new FakeWorkerSpawner(joinLatch: new Latch());
        $pool = $this->createPool();
        $pool->startWorker(self::createSlot(0), $this->listener);

        $stopping = async(static fn() => $pool->shutdown('test', Duration::seconds(0.05)));
        delay(0);
        $this->spawner->getLatestProcess(0)->crash();
        EventLoopTicks::settle();
        $this->timers->delay(Duration::seconds(2));
        $stopping->await();

        self::assertSame([], $this->listener->gone);
        self::assertTrue($this->spawner->getLatestProcess(0)->closed);
        self::assertSame(WorkerPhase::Stopped, $this->getPhaseOf(0));
    }

    public function testRestartWaitsForItsBackoff(): void
    {
        $pool = $this->createPool();
        $pool->startWorker(self::createSlot(0), $this->listener);

        $this->spawner->getLatestProcess(0)->crash();
        EventLoopTicks::settleUntil(fn(): bool => $this->getPhaseOf(0) === WorkerPhase::RestartScheduled);

        self::assertSame(WorkerPhase::RestartScheduled, $this->getPhaseOf(0));
        self::assertSame(1, $this->slots->countPendingRestarts());

        $this->timers->delay(Duration::milliseconds(self::RESTART_DELAY_SECONDS * 1_000 - 1));
        self::assertSame(1, $this->spawner->countSpawnedProcesses());

        $this->timers->delay(Duration::milliseconds(1));
        self::assertSame(2, $this->spawner->countSpawnedProcesses());
        self::assertSame(0, $this->slots->countPendingRestarts());
    }

    public function testWorkerIsQuarantinedOnceItsBudgetIsSpent(): void
    {
        $pool = $this->createPool(self::createSupervisionConfig(restartAttempts: 2));
        $pool->startWorker(self::createSlot(0), $this->listener);

        for ($crash = 0; $crash < 4; ++$crash) {
            $this->crashAndRestart(0);
        }

        self::assertSame(3, $this->spawner->countSpawnedProcesses(), 'One start plus two restarts, then nothing.');
        self::assertSame(WorkerPhase::Quarantined, $this->getPhaseOf(0));
        self::assertSame(0, $this->slots->countLiveWorkers());
        self::assertSame(0, $this->slots->countPendingRestarts());
        self::assertSame([0], $this->listener->quarantined);
    }

    public function testSpawnThatFailsAfterACrashIsRetried(): void
    {
        $pool = $this->createPool();
        $pool->startWorker(self::createSlot(0), $this->listener);

        $this->spawner->failNext(1);
        $this->crashAndRestart(0);
        $this->timers->delay(self::getRestartDelay());

        self::assertSame(3, $this->spawner->attempts, 'The failed respawn is retried.');
        self::assertSame(1, $this->slots->countLiveWorkers());
    }

    public function testFailedInitialStartIsRetried(): void
    {
        $pool = $this->createPool();
        $this->spawner->failNext(1);

        self::assertFalse($pool->startWorker(self::createSlot(0), $this->listener));
        self::assertSame(1, $this->slots->countPendingRestarts());

        $this->timers->delay(self::getRestartDelay());

        self::assertSame(1, $this->slots->countLiveWorkers());
        self::assertSame(0, $this->slots->countPendingRestarts());
    }

    public function testWorkerThatNeverStartsIsQuarantined(): void
    {
        $pool = $this->createPool(self::createSupervisionConfig(restartAttempts: 2));
        $this->spawner->failNext(10);

        $pool->startWorker(self::createSlot(0), $this->listener);
        $this->timers->delay(Duration::seconds(10));

        self::assertSame(3, $this->spawner->attempts, 'One start plus two retries, then nothing.');
        self::assertSame(0, $this->slots->countPendingRestarts());
        self::assertSame(WorkerPhase::Quarantined, $this->getPhaseOf(0));
    }

    public function testStoppedPoolStartsNothingAtAll(): void
    {
        $pool = $this->createPool();
        $pool->shutdown('test', Duration::seconds(0.01));

        self::assertFalse($pool->startWorker(self::createSlot(0), $this->listener));

        self::assertSame(0, $this->spawner->attempts);
        self::assertSame(0, $this->slots->countPendingRestarts());
    }

    public function testSpawnFinishingAfterShutdownIsClosed(): void
    {
        $spawning = new Latch();
        $this->spawner = new FakeWorkerSpawner(spawnLatch: $spawning);
        $pool = $this->createPool();

        $starting = async(fn(): bool => $pool->startWorker(self::createSlot(0), $this->listener));
        EventLoopTicks::settleUntil(fn(): bool => $this->getPhaseOf(0) === WorkerPhase::Spawning);

        self::assertSame(WorkerPhase::Spawning, $this->getPhaseOf(0));
        self::assertSame(1, $this->slots->countPendingRestarts(), 'A spawn in progress is not a failure to start.');

        $pool->shutdown('test', Duration::seconds(0.01));
        $spawning->open();

        self::assertFalse($starting->await());
        self::assertSame(0, $this->slots->countLiveWorkers());
        self::assertTrue($this->spawner->getLatestProcess(0)->closed);
        self::assertSame([], $this->listener->spawned);
        self::assertSame(WorkerPhase::Stopped, $this->getPhaseOf(0));
    }

    public function testWorkerThatNeverReportsReadyIsRestarted(): void
    {
        $pool = $this->createPool(self::createSupervisionConfig(readyTimeout: '20s'));
        $pool->startWorker(self::createSlot(0), $this->listener);

        $this->timers->delay(Duration::seconds(20));
        EventLoopTicks::settle();
        $this->timers->delay(self::getRestartDelay());

        self::assertSame(2, $this->spawner->attempts, 'A worker that never starts its apps is no better than a dead one.');
        self::assertTrue($this->spawner->spawned[0][0]->closed);
    }

    public function testWorkerThatReportsReadyIsLeftAlone(): void
    {
        $pool = $this->createPool(self::createSupervisionConfig(readyTimeout: '20s'));
        $pool->startWorker(self::createSlot(0), $this->listener);
        $handle = $this->getHandle(0);

        $pool->markReady($handle);
        $this->timers->delay(Duration::minutes(1));
        EventLoopTicks::settle();

        self::assertSame(1, $this->spawner->attempts);
        self::assertTrue($handle->isReady());
        self::assertSame(0, $this->timers->countPendingTimers(), 'Ready cancels the deadline.');
    }

    public function testReadyReportFromAReplacedWorkerChangesNothing(): void
    {
        $pool = $this->createPool(self::createSupervisionConfig(readyTimeout: '20s'));
        $pool->startWorker(self::createSlot(0), $this->listener);
        $first = $this->getHandle(0);

        $this->crashAndRestart(0);
        $pool->markReady($first);

        self::assertSame(1, $this->timers->countPendingTimers(), 'The replacement is still on its own deadline.');
        self::assertFalse($this->getHandle(0)->isReady());
    }

    public function testShutdownDropsEveryTimer(): void
    {
        $pool = $this->createPool(self::createSupervisionConfig(readyTimeout: '20s'));
        $pool->startWorker(self::createSlot(0), $this->listener);
        $pool->startWorker(self::createSlot(1), $this->listener);
        $this->spawner->getLatestProcess(1)->crash();
        EventLoopTicks::settle();

        self::assertSame(2, $this->timers->countPendingTimers(), 'One readiness deadline and one restart.');

        $pool->shutdown('test', Duration::seconds(0.01));
        $this->timers->delay(Duration::minutes(1));

        self::assertSame(0, $this->timers->countPendingTimers());
        self::assertSame(2, $this->spawner->attempts);
        self::assertSame(WorkerPhase::Stopped, $this->getPhaseOf(1));
    }

    public function testNothingIsRespawnedAfterShutdown(): void
    {
        $pool = $this->createPool();
        $pool->startWorker(self::createSlot(0), $this->listener);

        $pool->shutdown('test', Duration::seconds(0.01));
        $this->crashAndRestart(0);

        self::assertSame(1, $this->spawner->countSpawnedProcesses());
    }

    public function testMessageThatFailsToHandleDoesNotKillTheWorker(): void
    {
        $pool = $this->createPool();
        $pool->startWorker(self::createSlot(0), $this->listener);
        $this->listener->failOnMessage = true;

        $this->spawner->getLatestProcess(0)->channel->deliver(new Unsubscribe(new SubscriptionId('w0:0')));
        $this->spawner->getLatestProcess(0)->channel->deliver(new Unsubscribe(new SubscriptionId('w0:1')));
        EventLoopTicks::settleUntil(fn(): bool => \count($this->listener->messages) === 2);

        self::assertCount(2, $this->listener->messages, 'The reader keeps going after a handler throws.');
        self::assertSame([], $this->listener->gone);
        self::assertSame(1, $this->spawner->countSpawnedProcesses());
    }

    public function testSlotsAreListedInWorkerOrder(): void
    {
        $pool = $this->createPool();
        $pool->startWorker(self::createSlot(2), $this->listener);
        $pool->startWorker(self::createSlot(0), $this->listener);

        self::assertSame([0, 2], $this->slots->listSlotStates()->mapToList(static fn(WorkerSlotState $state): int => $state->slot->workerId->value));
    }

    public function testShutdownStopsAllWorkersConcurrently(): void
    {
        $joining = new Latch();
        $this->spawner = new FakeWorkerSpawner(joinLatch: $joining);
        $pool = $this->createPool();

        foreach ([0, 1, 2] as $workerId) {
            $pool->startWorker(self::createSlot($workerId), $this->listener);
        }

        $stopping = async(static fn() => $pool->shutdown('test', Duration::seconds(2.0)));
        EventLoopTicks::settleUntil(static fn(): bool => $joining->countWaiters() === 3);

        self::assertSame(3, $joining->countWaiters(), 'The three joins run together, not one after another.');

        $joining->open();
        $stopping->await();

        foreach ([0, 1, 2] as $workerId) {
            $process = $this->spawner->getLatestProcess($workerId);

            self::assertInstanceOf(Shutdown::class, $process->channel->sent[\count($process->channel->sent) - 1]);
            self::assertTrue($process->closed);
        }
    }

    public function testWorkerOverrunningTheGraceIsKilled(): void
    {
        $this->spawner = new FakeWorkerSpawner(joinLatch: new Latch());
        $pool = $this->createPool();
        $pool->startWorker(self::createSlot(0), $this->listener);

        $stopping = async(static fn() => $pool->shutdown('test', Duration::seconds(0.05)));
        delay(0);
        $this->timers->delay(Duration::seconds(2));
        $stopping->await();

        self::assertTrue($this->spawner->getLatestProcess(0)->closed);
        self::assertContains('Worker did not stop in time; killing it', $this->logger->listMessagesAt('warning'));
    }

    public function testTerminatedWorkersEndShutdownWithoutGrace(): void
    {
        $this->spawner = new FakeWorkerSpawner(joinLatch: new Latch());
        $pool = $this->createPool();
        $pool->startWorker(self::createSlot(0), $this->listener);

        $stopping = async(static fn() => $pool->shutdown('test', Duration::minutes(1)));
        delay(0);
        $pool->terminateWorkers();
        $stopping->await();

        self::assertTrue($this->spawner->getLatestProcess(0)->closed);
        self::assertSame([], $this->logger->listMessagesAt('warning'));
    }

    public function testSpawnThatNeverConnectsIsRetried(): void
    {
        $this->spawner = new FakeWorkerSpawner(spawnLatch: new Latch());
        $pool = $this->createPool();

        $starting = async(fn(): bool => $pool->startWorker(self::createSlot(0), $this->listener));
        EventLoopTicks::settleUntil(fn(): bool => $this->getPhaseOf(0) === WorkerPhase::Spawning);
        $this->timers->delay(Duration::seconds(10));

        self::assertFalse($starting->await());
        self::assertSame(WorkerPhase::RestartScheduled, $this->getPhaseOf(0));
        $failure = $this->findLogged('Could not start worker; its automations are not running')?->context['exception'] ?? null;
        self::assertInstanceOf(BrokerException::class, $failure);
        self::assertSame(BrokerError::WorkerSpawnTimedOut, $failure->reason);
    }

    public function testUnexpectedExitLogsTheWorkerSummary(): void
    {
        $pool = $this->createPool();
        $pool->startWorker(self::createSlot(0), $this->listener);

        $this->spawner->getLatestProcess(0)->crashWithSummary('worker stopped before bootstrap: protocol mismatch');
        EventLoopTicks::settleUntil(fn(): bool => $this->getPhaseOf(0) === WorkerPhase::RestartScheduled);

        self::assertSame('worker stopped before bootstrap: protocol mismatch', $this->findLogged('Worker exited')?->context['summary']);
    }

    public function testUnexpectedExitLogsTheWorkerFailure(): void
    {
        $pool = $this->createPool();
        $pool->startWorker(self::createSlot(0), $this->listener);
        $failure = new RuntimeException('kernel blew up');

        $this->spawner->getLatestProcess(0)->crashWithFailure($failure);
        EventLoopTicks::settleUntil(fn(): bool => $this->getPhaseOf(0) === WorkerPhase::RestartScheduled);

        self::assertSame($failure, $this->findLogged('Worker exited with an error')?->context['exception']);
        self::assertSame('error', $this->findLogged('Worker exited with an error')->level);
    }

    public function testExitThatNeverArrivesStillRestarts(): void
    {
        $this->spawner = new FakeWorkerSpawner(joinLatch: new Latch());
        $pool = $this->createPool();
        $pool->startWorker(self::createSlot(0), $this->listener);

        $this->spawner->getLatestProcess(0)->crash();
        EventLoopTicks::settle();
        $this->timers->delay(Duration::seconds(2));
        EventLoopTicks::settleUntil(fn(): bool => $this->getPhaseOf(0) === WorkerPhase::RestartScheduled);

        self::assertTrue($this->spawner->getLatestProcess(0)->closed);
        self::assertNull($this->findLogged('Worker exited'));
    }

    public function testWorkerKilledByTheBrokerIsNotJoined(): void
    {
        $pool = $this->createPool(self::createSupervisionConfig(readyTimeout: '20s'));
        $pool->startWorker(self::createSlot(0), $this->listener);

        $this->timers->delay(Duration::seconds(20));
        EventLoopTicks::settleUntil(fn(): bool => $this->getPhaseOf(0) === WorkerPhase::RestartScheduled);

        self::assertSame(0, $this->spawner->getLatestProcess(0)->joins);
        self::assertNull($this->findLogged('Worker exited'));
    }

    private function createPool(?SupervisionConfig $supervision = null): WorkerPool
    {
        $pools = WorkerPoolFixture::createWorkerPool(
            spawner: $this->spawner,
            supervision: $supervision ?? self::createSupervisionConfig(),
            timers: $this->timers,
            logger: $this->logger,
        );
        $this->slots = $pools->slots;

        return $pools->pool;
    }

    private function crashAndRestart(int $workerId): void
    {
        $this->spawner->getLatestProcess($workerId)->crash();
        EventLoopTicks::settle();
        $this->timers->delay(self::getRestartDelay());
    }

    private function findLogged(string $message): ?RecordedLog
    {
        return $this->logger->records->findFirstWhere(static fn(RecordedLog $record): bool => $record->message === $message);
    }

    private function getPhaseOf(int $workerId): ?WorkerPhase
    {
        return $this->slots->findSlotState(new WorkerId($workerId))?->phase;
    }

    private function getHandle(int $workerId): WorkerHandle
    {
        $handle = $this->slots->findHandleForWorker(new WorkerId($workerId));
        self::assertNotNull($handle);

        return $handle;
    }

    private static function getRestartDelay(): Duration
    {
        return Duration::seconds(self::RESTART_DELAY_SECONDS);
    }

    private static function createSupervisionConfig(int $restartAttempts = 5, string $readyTimeout = 'off'): SupervisionConfig
    {
        return ConfigFixture::createSupervisionConfig([
            'restart_attempts' => $restartAttempts,
            'restart_initial_delay' => self::RESTART_DELAY_SECONDS . 's',
            'restart_max_delay' => self::RESTART_DELAY_SECONDS . 's',
            'ping_interval' => 'off',
            'ready_timeout' => $readyTimeout,
        ]);
    }

    private static function createSlot(int $workerId): WorkerSlot
    {
        return new WorkerSlot(new WorkerId($workerId), AppDefinitionCollection::keyedByAppId([new AppDefinition(id: new AppId('demo'), class: Demo::class)]));
    }
}
