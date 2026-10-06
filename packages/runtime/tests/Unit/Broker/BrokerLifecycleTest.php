<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use Amp\Future;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Stewart\Client\Exception\HaClientError;
use Stewart\Client\Exception\HaClientException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Exception\ServiceCallError;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\AppRunningTotals;
use Stewart\Runtime\Broker\BrokerHaEvents;
use Stewart\Runtime\Broker\BrokerLifecycle;
use Stewart\Runtime\Broker\BrokerRun;
use Stewart\Runtime\Broker\BrokerWorkerEvents;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\ConnectionTracker;
use Stewart\Runtime\Broker\ControlPlane;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\EventFireProxy;
use Stewart\Runtime\Broker\EventRouter;
use Stewart\Runtime\Broker\LoopErrorLogger;
use Stewart\Runtime\Broker\ManifestCheck;
use Stewart\Runtime\Broker\Message\AppFailedHandler;
use Stewart\Runtime\Broker\Message\PongHandler;
use Stewart\Runtime\Broker\Message\WorkerMessageDispatcher;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\ServiceCallProxy;
use Stewart\Runtime\Broker\ServiceCallStatsRecorder;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Broker\WorkerStartup;
use Stewart\Runtime\Config\SupervisionConfig;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Ipc\Message\AppActivityReport;
use Stewart\Runtime\Ipc\Message\AppFailed;
use Stewart\Runtime\Ipc\Message\Bootstrap;
use Stewart\Runtime\Ipc\Message\EventFired;
use Stewart\Runtime\Ipc\Message\EventFireRequest;
use Stewart\Runtime\Ipc\Message\EventFireResult;
use Stewart\Runtime\Ipc\Message\HaConnectionLost;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Ipc\Message\RegistrySnapshot;
use Stewart\Runtime\Ipc\Message\ServiceCallFailed;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Ipc\Message\StateChangeBatch;
use Stewart\Runtime\Ipc\Message\StateResynced;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Ipc\Message\Subscribe;
use Stewart\Runtime\Ipc\Message\SubscriptionAck;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Lifecycle\ConnectionPhase;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\BootedBroker;
use Stewart\Runtime\Tests\Fixtures\Broker\BrokerKernelFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerSpawner;
use Stewart\Runtime\Tests\Fixtures\Broker\ReceivedEventFire;
use Stewart\Runtime\Tests\Fixtures\Broker\RecordingControlPlane;
use Stewart\Runtime\Tests\Fixtures\Broker\UnversionedManifest;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Runtime\Tests\Fixtures\Generated\Code\Manifest;
use Stewart\Store\GuardedStoreBackend;
use Stewart\Store\StoreDsn;
use Stewart\Store\StoreTiming;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Store\InMemoryStoreBackend;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

use function Amp\async;

#[CoversClass(BrokerLifecycle::class)]
#[CoversClass(BrokerRun::class)]
#[CoversClass(BrokerHaEvents::class)]
#[CoversClass(BrokerWorkerEvents::class)]
#[CoversClass(WorkerStartup::class)]
#[CoversClass(WorkerMessageDispatcher::class)]
#[CoversClass(AppFailedHandler::class)]
#[CoversClass(PongHandler::class)]
#[CoversClass(EventRouter::class)]
#[CoversClass(ServiceCallProxy::class)]
#[CoversClass(EventFireProxy::class)]
#[CoversClass(ManifestCheck::class)]
#[CoversClass(AppMetrics::class)]
#[CoversClass(AppStatusBuilder::class)]
#[CoversClass(AppRunningTotals::class)]
#[CoversClass(ServiceCallStatsRecorder::class)]
#[CoversClass(ConnectionTracker::class)]
#[CoversClass(DaemonStartTime::class)]
#[CoversClass(LoopErrorLogger::class)]
final class BrokerLifecycleTest extends TestCase
{
    use AssertsReason;

    private FakeHaSession $session;

    private ManualTimers $timers;

    private FakeWorkerSpawner $spawner;

    private BootedBroker $broker;

    private ?InMemoryStoreBackend $store = null;

    private ?ManifestCheck $manifest = null;

    private ?SupervisionConfig $supervision = null;

    private ?RecordingControlPlane $control = null;

    private RecordingLogger $logger;

    private SubscriptionRegistry $registry;

    private ConnectionTracker $connection;

    private AppMetrics $metrics;

    private AppPauseRegistry $pausedApps;

    private ?WorkerSlotRegistry $slots = null;

    /** @var Future<mixed>|null */
    private ?Future $running = null;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->session = new FakeHaSession();
        $this->spawner = new FakeWorkerSpawner();
        $this->logger = new RecordingLogger();
        $this->pausedApps = new AppPauseRegistry();
        $this->broker = $this->createBroker();
    }

    protected function tearDown(): void
    {
        $this->broker->run->stop('test over');
        $this->running?->await();
    }

    public function testBootstrapListsKnownAppIds(): void
    {
        $this->startBroker();

        $sent = $this->listEverythingSentToWorker(0);

        self::assertInstanceOf(Bootstrap::class, $sent[0]);
        self::assertSame(['demo', 'echo', 'retired'], $sent[0]->knownAppIds->collection->toStrings());
        self::assertSame([], $sent[0]->pausedAppIds->collection->toStrings());
    }

    public function testRestartedWorkerIsBootstrappedPaused(): void
    {
        $this->supervision = ConfigFixture::createSupervisionConfig(['restart_initial_delay' => '1ms', 'restart_max_delay' => '1ms', 'ping_interval' => 'off']);
        $this->broker = $this->createBroker();
        $this->startBroker();

        $this->pausedApps->pauseApp(new AppId('demo'));
        $this->crashAndRestart(0);

        $sent = $this->listEverythingSentToWorker(0);

        self::assertInstanceOf(Bootstrap::class, $sent[0]);
        self::assertSame(['demo'], $sent[0]->pausedAppIds->collection->toStrings());
    }

    public function testUnreachableStoreStopsBeforeConnecting(): void
    {
        $this->store = new InMemoryStoreBackend($this->timers->clock);
        $this->store->simulateOutage('valkey:6379 refused the connection');
        $this->broker = $this->createBroker();

        try {
            $this->broker->lifecycle->run();
            self::fail('An unreachable store is a configuration mistake, and the daemon must say so.');
        } catch (StoreException $e) {
            self::assertStringContainsString('valkey:6379', $e->getMessage());
        }

        self::assertFalse($this->session->isOpen(), 'Nothing should connect to Home Assistant first.');
        self::assertSame([], $this->spawner->spawned, 'And no worker should have been started.');
    }

    public function testEveryWorkerIsBootstrappedWithTheSessionState(): void
    {
        $this->startBroker();

        foreach ([0, 1] as $workerId) {
            $sent = $this->listEverythingSentToWorker($workerId);

            self::assertInstanceOf(Bootstrap::class, $sent[0]);
            self::assertSame($workerId, $sent[0]->workerId->value);
            self::assertSame('Europe/Budapest', $sent[0]->timeZone);
            self::assertEquals(Duration::seconds(35), $sent[0]->settings->callTimeout);
            self::assertInstanceOf(RegistrySnapshot::class, $sent[1]);
            self::assertInstanceOf(StateSnapshot::class, $sent[2]);
            self::assertSame(1, $sent[2]->revision);
        }
    }

    public function testStateChangeReachesEveryWorker(): void
    {
        $this->startBroker();

        $change = new StateChange(new EntityId('light.hall'), null, new EntityState(new EntityId('light.hall'), 'on'));
        $this->session->listener?->stateChanged($change);
        EventLoopTicks::settleUntil(fn(): bool => $this->hasEachWorkerReceivedOne(StateChangeBatch::class));

        foreach ([0, 1] as $workerId) {
            $batches = $this->listSentToWorker($workerId, StateChangeBatch::class);

            self::assertCount(1, $batches);
            self::assertSame(1, $batches[0]->changes->collection->count());
            $codec = IpcCodec::createForWorkerBootstrap();
            $decoded = $codec->decodeMessage($codec->encodeMessage($batches[0]));
            self::assertInstanceOf(StateChangeBatch::class, $decoded);
            self::assertEquals([$change], $decoded->changes->collection->listValues());
        }
    }

    public function testStateSubscriptionAnnouncedToBrokerIsRefused(): void
    {
        $this->startBroker();

        $this->spawner->getLatestProcess(0)->channel->deliver(new Subscribe(new SubscriptionId('w0:0'), self::createScope('demo'), SubscriptionKind::StateChange, Selector::exact('light.hall')));
        EventLoopTicks::settleUntil(fn(): bool => \count($this->listSentToWorker(0, SubscriptionAck::class)) === 1);

        $acks = $this->listSentToWorker(0, SubscriptionAck::class);

        self::assertCount(1, $acks);
        self::assertFalse($acks[0]->accepted);
        self::assertSame([], $this->registry->listSubscriptions()->listValues());
    }

    public function testEventOnlyReachesTheWorkersThatAskedForIt(): void
    {
        $this->startBroker();

        $this->spawner->getLatestProcess(0)->channel->deliver(new Subscribe(new SubscriptionId('w0:0'), self::createScope('demo'), SubscriptionKind::Event, Selector::exact('zha_event')));
        EventLoopTicks::settle();

        $this->session->listener?->eventFired(new HaEvent('zha_event', ['command' => 'toggle']));
        $this->session->listener?->eventFired(new HaEvent('hue_event'));
        EventLoopTicks::settle();

        $delivered = $this->listSentToWorker(0, EventFired::class);

        self::assertCount(1, $delivered);
        self::assertSame('zha_event', $delivered[0]->event->type);
        self::assertEquals([new SubscriptionId('w0:0')], $delivered[0]->deliverTo);
        self::assertSame([], $this->listSentToWorker(1, EventFired::class));
    }

    public function testServiceCallIsAnsweredOnTheWorkerThatAsked(): void
    {
        $this->startBroker();

        $this->spawner->getLatestProcess(1)->channel->deliver(new ServiceCallRequest(new CorrelationId('1:0'), self::createScope('echo'), 'light', 'turn_on', [], null, false));
        EventLoopTicks::settleUntil(fn(): bool => \count($this->listSentToWorker(1, ServiceCallResult::class)) === 1);

        $results = $this->listSentToWorker(1, ServiceCallResult::class);

        self::assertCount(1, $results);
        self::assertSame('1:0', $results[0]->correlationId->value);
        self::assertSame([], $this->listSentToWorker(0, ServiceCallResult::class));
    }

    public function testEventFireIsAnsweredOnTheWorkerThatAsked(): void
    {
        $this->startBroker();

        $this->spawner->getLatestProcess(1)->channel->deliver(new EventFireRequest(new CorrelationId('1:0'), self::createScope('echo'), 'doorbell_pressed', ['button' => 'front']));
        EventLoopTicks::settleUntil(fn(): bool => \count($this->listSentToWorker(1, EventFireResult::class)) === 1);

        $results = $this->listSentToWorker(1, EventFireResult::class);

        self::assertCount(1, $results);
        self::assertSame('1:0', $results[0]->correlationId->value);
        self::assertSame(FakeHaSession::FIRE_CONTEXT_PREFIX . '1', $results[0]->context->id);
        self::assertEquals([new ReceivedEventFire('doorbell_pressed', ['button' => 'front'])], $this->session->receivedEventFires);
        self::assertSame([], $this->listSentToWorker(0, EventFireResult::class));
    }

    public function testReconnectBroadcastsTheRebuiltState(): void
    {
        $this->startBroker();

        $this->session->revision = 9;
        $this->session->listener?->connectionLost('gone', Instant::fromEpochMicroseconds(0));
        $this->session->listener?->reconnected(Duration::seconds(4.5));
        EventLoopTicks::settleUntil(fn(): bool => $this->hasEachWorkerReceivedOne(StateResynced::class));

        foreach ([0, 1] as $workerId) {
            $sent = $this->listEverythingSentToWorker($workerId);
            $lost = array_values(array_filter($sent, static fn(object $m): bool => $m instanceof HaConnectionLost));
            $resyncs = array_values(array_filter($sent, static fn(object $m): bool => $m instanceof StateResynced));

            self::assertCount(1, $lost);
            self::assertSame('gone', $lost[0]->reason);
            self::assertCount(1, $resyncs);
            self::assertSame(9, $resyncs[0]->revision);
            self::assertEquals(Duration::milliseconds(4_500), $resyncs[0]->outage);
            self::assertLessThan(array_search($resyncs[0], $sent, true), array_search($lost[0], $sent, true));
        }
    }

    public function testWorkerThatStartsDuringAnOutageLearnsOfIt(): void
    {
        $spawning = new Latch();
        $this->spawner = new FakeWorkerSpawner(spawnLatch: $spawning);
        $this->broker = $this->createBroker();
        $this->running = async($this->broker->lifecycle->run(...));
        EventLoopTicks::settle();

        $this->session->listener?->connectionLost('gone', Instant::fromEpochMicroseconds(0));
        $spawning->open();
        EventLoopTicks::settleUntil(fn(): bool => isset($this->spawner->spawned[0]) && \count($this->listEverythingSentToWorker(0)) >= 4);

        $sent = $this->listEverythingSentToWorker(0);

        self::assertInstanceOf(Bootstrap::class, $sent[0]);
        self::assertInstanceOf(RegistrySnapshot::class, $sent[1]);
        self::assertInstanceOf(StateSnapshot::class, $sent[2]);
        self::assertInstanceOf(HaConnectionLost::class, $sent[3]);
    }

    public function testRegistryUpdateReachesEveryWorker(): void
    {
        $this->startBroker();

        $this->session->listener?->eventFired(new HaEvent('area_registry_updated'));
        $this->timers->delay(Duration::seconds(1));
        EventLoopTicks::settleUntil(fn(): bool => $this->hasEachWorkerReceivedRegistryRevision(2));

        self::assertSame(1, $this->session->registryRefreshes);
    }

    public function testFailedRegistryRefreshSendsNothing(): void
    {
        $this->startBroker();
        $this->session->registryRefreshSucceeds = false;

        $this->session->listener?->eventFired(new HaEvent('entity_registry_updated'));
        $this->timers->delay(Duration::seconds(1));
        EventLoopTicks::settle();

        self::assertSame(1, $this->session->registryRefreshes);
        self::assertFalse($this->hasEachWorkerReceivedRegistryRevision(2));
    }

    public function testWorkerRespawnedDuringOutageIsResynced(): void
    {
        $this->supervision = ConfigFixture::createSupervisionConfig(['restart_initial_delay' => '1ms', 'restart_max_delay' => '1ms', 'ping_interval' => 'off']);
        $this->broker = $this->createBroker();
        $this->startBroker();

        $this->session->listener?->connectionLost('gone', Instant::fromEpochMicroseconds(0));
        $this->crashAndRestart(0);

        self::assertCount(2, $this->spawner->spawned[0], 'A restart does not wait for Home Assistant.');

        $this->session->listener?->reconnected(Duration::seconds(1));
        EventLoopTicks::settleUntil(fn(): bool => \count($this->listEverythingSentToWorker(0)) >= 6);

        $sent = $this->listEverythingSentToWorker(0);

        self::assertInstanceOf(Bootstrap::class, $sent[0]);
        self::assertInstanceOf(RegistrySnapshot::class, $sent[1]);
        self::assertInstanceOf(StateSnapshot::class, $sent[2]);
        self::assertInstanceOf(HaConnectionLost::class, $sent[3]);
        self::assertInstanceOf(RegistrySnapshot::class, $sent[4], 'The registry precedes the resync.');
        self::assertInstanceOf(StateResynced::class, $sent[5]);
    }

    public function testServiceCallFailsWhileDisconnected(): void
    {
        $this->startBroker();
        $this->session->connected = false;

        $this->spawner->getLatestProcess(1)->channel->deliver(new ServiceCallRequest(new CorrelationId('1:0'), self::createScope('echo'), 'light', 'turn_on', [], null, false));
        EventLoopTicks::settleUntil(fn(): bool => \count($this->listSentToWorker(1, ServiceCallFailed::class)) === 1);

        $errors = $this->listSentToWorker(1, ServiceCallFailed::class);

        self::assertCount(1, $errors);
        self::assertSame(ServiceCallError::Unreachable, $errors[0]->getReason());
        self::assertSame(0, $this->session->calls);
    }

    public function testFatalConnectionFailureEndsTheRunWithThatError(): void
    {
        $this->session->openFailure = HaClientException::tokenRejected('token rejected');

        $this->assertThrowsReason(HaClientError::TokenRejected, fn() => $this->broker->lifecycle->run());
    }

    public function testWorkersAreSpawnedConcurrently(): void
    {
        $spawns = new Latch();
        $this->spawner = new FakeWorkerSpawner(spawnLatch: $spawns);
        $this->broker = $this->createBroker();
        $this->startBroker();

        EventLoopTicks::settleUntil(static fn(): bool => $spawns->countWaiters() === 2);
        self::assertSame(2, $this->spawner->attempts);

        $spawns->open();
        EventLoopTicks::settleUntil(fn(): bool => $this->slots?->countLiveWorkers() === 2);
    }

    public function testFatalFailureDuringSpawnEndsRunWithThatError(): void
    {
        $spawns = new Latch();
        $this->spawner = new FakeWorkerSpawner(spawnLatch: $spawns);
        $this->broker = $this->createBroker();
        $running = async($this->broker->lifecycle->run(...));
        EventLoopTicks::settle();

        $this->session->listener?->connectionFailed(HaClientException::tokenRejected('token rejected'));
        $spawns->open();

        $this->assertThrowsReason(HaClientError::TokenRejected, fn() => $running->await());
    }

    public function testStopEndsTheRunAndStopsTheWorkers(): void
    {
        $this->startBroker();

        $this->broker->run->stop('test');
        $this->running?->await();
        $this->running = null;

        self::assertFalse($this->session->isOpen());
        self::assertTrue($this->spawner->getLatestProcess(0)->closed);
        self::assertSame(0, $this->slots?->countLiveWorkers());
    }

    public function testKillingWorkersNowEndsAStopEarly(): void
    {
        $this->spawner = new FakeWorkerSpawner(joinLatch: new Latch());
        $this->broker = $this->createBroker();
        $this->startBroker();

        $stopping = async(fn() => $this->broker->run->stop('test'));
        EventLoopTicks::settleUntil(fn(): bool => $this->broker->run->isStopping());
        $this->broker->run->killWorkersNow();
        $stopping->await();
        $this->running?->await();
        $this->running = null;

        self::assertFalse($this->broker->run->isStopping());
        self::assertTrue($this->spawner->getLatestProcess(0)->closed);
        self::assertTrue($this->spawner->getLatestProcess(1)->closed);
    }

    public function testThrowingStopStepStillEndsRun(): void
    {
        $this->startBroker();
        $failure = new RuntimeException('session already torn down');
        $this->session->closeFailure = $failure;

        $this->broker->run->stop('test');

        try {
            $this->running?->await();
            self::fail('The run must end with the step failure instead of hanging.');
        } catch (RuntimeException $e) {
            self::assertSame($failure, $e);
        } finally {
            $this->running = null;
        }

        self::assertTrue($this->spawner->getLatestProcess(0)->closed);
        self::assertContains('Could not close the Home Assistant session while shutting down', $this->logger->listMessagesAt('error'));
    }

    public function testStartTimeIsWhenTheRunBegan(): void
    {
        $this->timers->delay(Duration::seconds(30));
        $runBegan = $this->timers->clock->getNow();

        $this->startBroker();
        $this->timers->delay(Duration::seconds(5));

        self::assertEquals($runBegan, $this->broker->startTime->getStartedAt());
    }

    public function testStopDuringOpenLeavesConnectionUnmarked(): void
    {
        $this->session->duringOpen = fn() => $this->broker->run->stop('signal');

        $this->broker->lifecycle->run();

        self::assertNotSame(ConnectionPhase::Connected, $this->connection->state->phase);
        self::assertSame([], $this->spawner->spawned);
    }

    public function testStopDuringStoreProbeStartsNothing(): void
    {
        $this->store = new InMemoryStoreBackend($this->timers->clock);
        $this->control = new RecordingControlPlane();
        $this->broker = $this->createBroker();
        $this->store->duringProbe = fn() => $this->broker->run->stop('signal');

        $this->broker->lifecycle->run();

        self::assertFalse($this->control->started);
        self::assertNull($this->session->listener);
        self::assertSame([], $this->spawner->spawned);
    }

    public function testStrayFailedFutureIsLoggedAndRunGoesOn(): void
    {
        $this->startBroker();

        async(static fn() => throw new RuntimeException('nobody awaits this'));
        EventLoopTicks::settleUntil(fn(): bool => $this->logger->listMessagesAt('critical') !== []);

        self::assertSame(['Unhandled error in the event loop'], $this->logger->listMessagesAt('critical'));
        self::assertTrue($this->broker->run->isRunning());
    }

    public function testLoopErrorHandlerIsRestoredAfterTheRun(): void
    {
        $previous = EventLoop::getErrorHandler();
        $this->startBroker();

        self::assertNotSame($previous, EventLoop::getErrorHandler());

        $this->broker->run->stop('test');
        $this->running?->await();
        $this->running = null;

        self::assertSame($previous, EventLoop::getErrorHandler());
    }

    public function testMetricsAndConnectionFollowWhatTheBrokerSees(): void
    {
        $this->startBroker();

        self::assertSame(ConnectionPhase::Connected, $this->connection->state->phase);

        $worker = $this->spawner->getLatestProcess(1)->channel;
        $worker->deliver(new ServiceCallRequest(new CorrelationId('1:0'), self::createScope('echo'), 'light', 'turn_on', [], null, false));
        $worker->deliver(new Pong(1, Duration::zero(), 0, [new AppActivityReport(self::createScope('echo'), AppState::Running, 2, 1, 5, 0, 1, 0, 1, 0)]));
        $worker->deliver(new AppFailed(self::createScope('echo'), AppFailurePhase::Handler, 'RuntimeException', 'boom', '', null, 1, null));
        EventLoopTicks::settleUntil(fn(): bool => $this->readAppStatusAt(1)->lastFailure !== null && \count($this->readAppStatusAt(1)->serviceCalls) === 1);

        $echo = $this->readAppStatusAt(1);
        self::assertSame(['echo', AppState::Running, 2, 5, 1], [$echo->id, $echo->state, $echo->subscriptions, $echo->counters->delivered, $echo->counters->failures]);
        self::assertSame('boom', $echo->lastFailure?->message);
        self::assertCount(1, $echo->serviceCalls);
        self::assertSame(ServiceCallOutcome::Succeeded, $echo->serviceCalls[0]->outcome);

        $this->session->listener?->connectionLost('gone', Instant::fromEpochMicroseconds(0));
        self::assertSame(ConnectionPhase::Reconnecting, $this->connection->state->phase);
        $this->session->listener?->reconnected(Duration::seconds(1.5));
        self::assertSame([ConnectionPhase::Connected, 1], [$this->connection->state->phase, $this->connection->state->reconnects]);
        self::assertEquals(Duration::seconds(1.5), $this->connection->state->lastOutage);
    }

    public function testActivityReportsAddUpAcrossARespawnedWorker(): void
    {
        $this->supervision = ConfigFixture::createSupervisionConfig(['ping_interval' => 'off', 'restart_initial_delay' => '1ms', 'restart_max_delay' => '1ms']);
        $this->broker = $this->createBroker();
        $this->startBroker();

        $this->spawner->getLatestProcess(0)->channel->deliver(new Pong(1, Duration::zero(), 0, [new AppActivityReport(self::createScope('demo'), AppState::Running, 0, 0, 4, 0, 0, 0, 0, 0)]));
        $this->spawner->getLatestProcess(0)->channel->deliver(new Pong(2, Duration::zero(), 0, [new AppActivityReport(self::createScope('demo'), AppState::Running, 0, 0, 6, 0, 0, 0, 0, 0)]));
        EventLoopTicks::settleUntil(fn(): bool => $this->readAppStatusAt(0)->counters->delivered === 6);
        $this->crashAndRestart(0);
        $this->spawner->getLatestProcess(0)->channel->deliver(new Pong(3, Duration::zero(), 0, [new AppActivityReport(self::createScope('demo'), AppState::Running, 0, 0, 3, 0, 0, 0, 0, 0)]));
        EventLoopTicks::settleUntil(fn(): bool => $this->readAppStatusAt(0)->counters->delivered === 9);

        self::assertSame(9, $this->readAppStatusAt(0)->counters->delivered, 'Six before the crash, three since.');
    }

    /**
     * @template T of object
     * @param class-string<T> $messageClass
     * @return list<T>
     */
    private function listSentToWorker(int $workerId, string $messageClass): array
    {
        return $this->spawner->getLatestProcess($workerId)->channel->listSentOfType($messageClass);
    }

    /** @return list<object> */
    private function listEverythingSentToWorker(int $workerId): array
    {
        return $this->spawner->getLatestProcess($workerId)->channel->sent;
    }

    /** @param class-string $messageClass */
    private function hasEachWorkerReceivedOne(string $messageClass): bool
    {
        return array_all([0, 1], fn(int $workerId): bool => \count($this->listSentToWorker($workerId, $messageClass)) === 1);
    }

    private function readAppStatusAt(int $index): AppStatus
    {
        return new AppStatusBuilder($this->metrics)->buildAppStatuses()->listValues()[$index];
    }

    private function startBroker(): void
    {
        $this->running = async($this->broker->lifecycle->run(...));
        EventLoopTicks::settle();
    }

    private function crashAndRestart(int $workerId): void
    {
        $this->spawner->getLatestProcess($workerId)->crash();
        EventLoopTicks::settle();
        $this->timers->delay(Duration::milliseconds(1));
        EventLoopTicks::settle();
    }

    public function testInstanceThatHasMovedOnGetsExactlyOneWarning(): void
    {
        $this->manifest = new ManifestCheck(Manifest::class);
        $this->session->entityIds = [...Manifest::listEntityIds(), 'light.landing'];
        $this->broker = $this->createBroker();

        $this->startBroker();

        self::assertSame(
            ['Generated entity classes no longer match Home Assistant; run stewart generate'],
            $this->logger->listMessagesAt('warning'),
        );
        self::assertSame(['light.landing'], $this->logger->records->getFirst()?->context['added']);
    }

    public function testMatchingInstanceSaysNothing(): void
    {
        $this->manifest = new ManifestCheck(Manifest::class);
        $this->session->entityIds = Manifest::listEntityIds();
        $this->broker = $this->createBroker();

        $this->startBroker();

        self::assertSame([], $this->logger->listMessagesAt('warning'));
    }

    public function testOlderGeneratedFormatGetsAWarning(): void
    {
        $this->manifest = new ManifestCheck(UnversionedManifest::class);
        $this->session->entityIds = Manifest::listEntityIds();
        $this->broker = $this->createBroker();

        $this->startBroker();

        self::assertSame(
            ['Generated classes were written by another stewart generate format; run stewart generate'],
            $this->logger->listMessagesAt('warning'),
        );
        self::assertSame(0, $this->logger->records->getFirst()?->context['format']);
    }

    public function testEntityLeftOutOfGenerationIsNotDrift(): void
    {
        $this->manifest = new ManifestCheck(Manifest::class);
        $this->session->entityIds = [...Manifest::listEntityIds(), ...Manifest::listIgnoredEntityIds()];
        $this->broker = $this->createBroker();

        $this->startBroker();

        self::assertSame([], $this->logger->listMessagesAt('warning'));
    }

    private function createBroker(): BootedBroker
    {
        $supervision = $this->supervision ?? ConfigFixture::createSupervisionConfig(['ping_interval' => 'off']);
        $pools = WorkerPoolFixture::createWorkerPool($this->spawner, $supervision, $this->timers, $this->logger, new OutboxLimits(100, 256));
        $this->slots = $pools->slots;
        $apps = [new AppDefinition(new AppId('demo'), Demo::class), new AppDefinition(new AppId('echo'), Demo::class)];
        $assignment = WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([$apps[0]])), new WorkerSlot(new WorkerId(1), AppDefinitionCollection::keyedByAppId([$apps[1]]))]);
        $this->registry = new SubscriptionRegistry();
        $this->connection = new ConnectionTracker($pools->clock);
        $this->metrics = new AppMetrics($assignment, $pools->clock);

        $overrides = new SyntheticServices()
            ->withService(RevoltTimers::class, $this->timers)
            ->withService(SupervisionConfig::class, $supervision)
            ->withService(SubscriptionRegistry::class, $this->registry)
            ->withService(ConnectionTracker::class, $this->connection)
            ->withService(AppMetrics::class, $this->metrics)
            ->withService(AppPauseRegistry::class, $this->pausedApps);
        $yaml = ['shutdown_grace' => '10ms'];

        if ($this->store !== null) {
            $overrides = $overrides->withService(GuardedStoreBackend::class, new GuardedStoreBackend($this->store, StoreDsn::parse('redis://valkey:6379/0'), new StoreTiming(Duration::seconds(2), Duration::seconds(5)), $pools->clock));
            $yaml['persistence'] = ['url' => 'redis://valkey:6379/0'];
        }

        if ($this->manifest !== null) {
            $overrides = $overrides->withService(ManifestCheck::class, $this->manifest);
        }

        if ($this->control !== null) {
            $overrides = $overrides->withService(ControlPlane::class, $this->control);
        }

        return BrokerKernelFixture::boot(
            $this->session,
            $pools,
            $assignment,
            AppIdCollection::fromIds([new AppId('demo'), new AppId('echo'), new AppId('retired')]),
            $this->logger,
            $yaml,
            $overrides,
        );
    }

    private static function createScope(string $appId): ResourceScope
    {
        return ResourceScope::forApp(new AppId($appId));
    }

    private function hasEachWorkerReceivedRegistryRevision(int $revision): bool
    {
        return array_all([0, 1], fn(int $workerId): bool => array_any(
            $this->listSentToWorker($workerId, RegistrySnapshot::class),
            static fn(RegistrySnapshot $snapshot): bool => $snapshot->revision === $revision,
        ));
    }
}
