<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Control;

use LogicException;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\ServiceCallProxy;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerPool;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
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

    public ServiceCallProxy $serviceCalls;

    public WorkerPoolFixture $pools;

    private RecordingPoolListener $listener;

    public function __construct(public FakeWorkerSpawner $spawner = new FakeWorkerSpawner())
    {
        $this->timers = new ManualTimers();
        $this->session = FakeHaSession::createOpened();
        $this->heldServiceCalls = $this->session->holdCalls();
        $this->registry = new SubscriptionRegistry();
        $this->metrics = new AppMetrics(WorkerSlotCollection::fromWorkerSlots([self::createSlot(0), self::createSlot(1)]), $this->timers->clock);
        $policy = ConfigFixture::createServiceCallPolicy();
        $this->callSlots = new HaCallSlots($policy, new NullLogger());
        $this->serviceCalls = new ServiceCallProxy($this->session, $this->callSlots, $this->metrics, $policy, $this->timers->clock, new NullLogger());
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
