<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Control;

use LogicException;
use Psr\Log\NullLogger;
use Stewart\Client\Component\ComponentInstance;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\Component\ComponentTracker;
use Stewart\Runtime\Broker\ConnectionTracker;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\Deploy\DeployState;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerPool;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Control\Assembler\StoreHealthBuilder;
use Stewart\Runtime\Control\Assembler\WorkerStatusBuilder;
use Stewart\Runtime\Control\SnapshotAssembler;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\StewartVersion;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHomeAssistant;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerSpawner;
use Stewart\Runtime\Tests\Fixtures\Broker\RecordingPoolListener;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Store\StoreHealth;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

final readonly class BrokerStateFixture
{
    public const int RESTART_DELAY_SECONDS = 5;

    public ManualTimers $timers;

    public FakeHaSession $session;

    public Latch $heldServiceCalls;

    public SubscriptionRegistry $registry;

    public AppMetrics $metrics;

    public HaCallSlots $callSlots;

    public WorkerPoolFixture $pools;

    private RecordingPoolListener $listener;

    public function __construct(public FakeWorkerSpawner $spawner = new FakeWorkerSpawner())
    {
        $this->timers = new ManualTimers();
        $this->session = FakeHaSession::createOpened();
        $this->heldServiceCalls = new Latch();
        $this->registry = new SubscriptionRegistry();
        $this->metrics = new AppMetrics(WorkerSlotCollection::fromList([self::createSlot(0), self::createSlot(1)]), $this->timers->clock);
        $this->callSlots = new HaCallSlots(ConfigFixture::createServiceCallPolicy(), $this->metrics, $this->timers->clock, new NullLogger());
        $this->listener = new RecordingPoolListener();
        $this->pools = WorkerPoolFixture::createWorkerPool(
            spawner: $spawner,
            supervision: ConfigFixture::createSupervisionConfig([
                'restart_attempts' => 2,
                'restart_initial_delay' => self::RESTART_DELAY_SECONDS . 's',
                'restart_max_delay' => self::RESTART_DELAY_SECONDS . 's',
                'ping_interval' => 'off',
            ]),
            timers: $this->timers,
        );
    }

    public function getPool(): WorkerPool
    {
        return $this->pools->pool;
    }

    public function startWorker(int $workerId): void
    {
        $this->getPool()->startWorker(self::createSlot($workerId), $this->listener);
    }

    public function restartWorker(int $workerId): void
    {
        $this->getPool()->restartWorker(self::createSlot($workerId));
    }

    public function getLiveHandleOf(int $workerId): WorkerHandle
    {
        return $this->pools->slots->findHandleForWorker(new WorkerId($workerId)) ?? throw new LogicException(\sprintf('Worker %d is not live.', $workerId));
    }

    public function crashWorker(int $workerId): void
    {
        $this->spawner->getLatestProcess($workerId)->crash();
        EventLoopTicks::settle();
    }

    public function reportStoreHealth(int $workerId, StoreHealth $store): void
    {
        $this->pools->watchdog->recordPong($this->getLiveHandleOf($workerId), new Pong(nonce: 0, loopLag: Duration::zero(), memoryBytes: 0, store: $store));
    }

    public function startHeldServiceCall(int $workerId): void
    {
        $request = new ServiceCallRequest(new CorrelationId($workerId . ':1'), ResourceScope::forApp(new AppId($workerId === 0 ? 'demo' : 'echo')), 'light', 'turn_on', [], null, false);
        $held = $this->heldServiceCalls;

        $this->callSlots->runBounded($this->getLiveHandleOf($workerId), $request, static function () use ($held, $request): ServiceCallResult {
            $held->waitUntilOpen();

            return new ServiceCallResult($request->correlationId, new ServiceResponse('light', 'turn_on'));
        });
    }

    public function createSnapshotAssembler(
        DaemonStartTime $startTime,
        ConnectionTracker $connection,
        ?AppStatusBuilder $appStatuses = null,
        DeployState $deployState = new DeployState(),
        ?ComponentTracker $componentTracker = null,
        string $componentInstance = 'default',
    ): SnapshotAssembler {
        $pools = $this->pools;

        return new SnapshotAssembler(
            session: $this->session,
            stewartVersion: new StewartVersion('0.1.0-test'),
            startTime: $startTime,
            connection: $connection,
            slots: $pools->slots,
            callSlots: $this->callSlots,
            registry: $this->registry,
            deployState: $deployState,
            componentTracker: $componentTracker ?? new ComponentTracker($this->timers->clock),
            expose: new ExposeConfig(ComponentInstance::parse($componentInstance), Duration::seconds(10)),
            workerStatuses: new WorkerStatusBuilder($pools->slots, $pools->watchdog, $pools->restartPolicy, $this->callSlots),
            appStatuses: $appStatuses ?? new AppStatusBuilder($this->metrics, new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]), $startTime), FakeHomeAssistant::createUnreachable()->exposures),
            storeHealth: new StoreHealthBuilder($pools->slots, ConfigFixture::createPersistenceConfig()),
            clock: $this->timers->clock,
        );
    }

    public function stopEverything(): void
    {
        $this->getPool()->shutdown('test over', Duration::zero());
        $this->heldServiceCalls->open();
    }

    private static function createSlot(int $workerId): WorkerSlot
    {
        return new WorkerSlot(new WorkerId($workerId), AppDefinitionCollection::keyedByAppId([new AppDefinition(id: new AppId($workerId === 0 ? 'demo' : 'echo'), class: Demo::class)]));
    }
}
