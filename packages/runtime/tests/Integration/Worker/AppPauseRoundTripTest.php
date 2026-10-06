<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Worker;

use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Broker\BrokerLifecycle;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\ControlAddress;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Control\Client\ControlClient;
use Stewart\Runtime\Control\Client\ControlTarget;
use Stewart\Runtime\Control\Protocol\Codec\FrameCodec;
use Stewart\Runtime\Control\Request\PauseAppRequestHandler;
use Stewart\Runtime\Control\Request\ResumeAppRequestHandler;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\BootedBroker;
use Stewart\Runtime\Tests\Fixtures\Broker\BrokerKernelFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\ReceivedServiceCall;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Protocol\SerialHandler;
use Stewart\Runtime\Tests\Fixtures\Worker\InMemoryWorkerSpawner;
use Stewart\Runtime\Time\SystemClock;
use Stewart\Store\GuardedStoreBackend;
use Stewart\Store\StoreDsn;
use Stewart\Store\StoreTiming;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Store\InMemoryStoreBackend;

use function Amp\async;

#[CoversClass(AppPauseService::class)]
#[CoversClass(PauseAppRequestHandler::class)]
#[CoversClass(ResumeAppRequestHandler::class)]
#[CoversClass(BrokerLifecycle::class)]
final class AppPauseRoundTripTest extends TestCase
{
    private const float WAIT_SECONDS = 5;

    private const string CONTROL_TOKEN = 'round-trip-token';

    private const string STORE_URL = 'redis://valkey:6379/0';

    /** @var Future<mixed>|null */
    private ?Future $running = null;

    public function testPausedAppSkipsChangesUntilResumed(): void
    {
        $session = new FakeHaSession();
        $logger = new RecordingLogger();
        $pools = WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $logger, outboxLimits: new OutboxLimits(100, 256));
        $appId = new AppId('serial-handler');
        $socketPath = sys_get_temp_dir() . '/stw-' . bin2hex(random_bytes(4)) . '.sock';
        $broker = BrokerKernelFixture::boot(
            $session,
            $pools,
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition($appId, SerialHandler::class)]))]),
            AppIdCollection::fromIds([$appId]),
            $logger,
            ['shutdown_grace' => '1s', 'control' => ['listen' => 'unix://' . $socketPath, 'token' => self::CONTROL_TOKEN]],
        );
        $control = new ControlTarget(ControlAddress::parse('unix://' . $socketPath), self::CONTROL_TOKEN);
        $client = new ControlClient(new FrameCodec(FrameCodec::createControlWireMapper()), new ProjectRoot(sys_get_temp_dir()), new RevoltTimers());
        $ready = $logger->waitForMessage('Worker ready');

        /** @var Future<null> $running */
        $running = async($broker->lifecycle->run(...));
        $ready->await(new TimeoutCancellation(self::WAIT_SECONDS));

        self::assertTrue($client->pauseApp($control, $appId, Duration::seconds(self::WAIT_SECONDS))->changed);
        self::assertFalse($client->pauseApp($control, $appId, Duration::seconds(self::WAIT_SECONDS))->changed, 'Pausing a paused app changes nothing.');
        $this->changeSerial($session, '1', '2');

        self::assertTrue($client->resumeApp($control, $appId, Duration::seconds(self::WAIT_SECONDS))->changed);
        $called = $session->waitForNextCall();
        $this->changeSerial($session, '2', '3');
        $called->await(new TimeoutCancellation(self::WAIT_SECONDS));

        self::assertCount(1, $session->receivedCalls);
        self::assertSame(['value' => '3'], $session->receivedCalls[0]->data);

        $broker->run->stop('test done');
        $running->await(new TimeoutCancellation(self::WAIT_SECONDS));
        self::assertFileDoesNotExist($socketPath, 'The lifecycle stops the control socket it started.');
    }

    public function testAppConfiguredPausedHandlesNothing(): void
    {
        $session = new FakeHaSession();
        $logger = new RecordingLogger();
        $pools = WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $logger, outboxLimits: new OutboxLimits(100, 256));
        $running = new AppId('serial-handler');
        $paused = new AppId('serial-paused');
        $broker = BrokerKernelFixture::boot(
            $session,
            $pools,
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([
                new AppDefinition($running, SerialHandler::class),
                new AppDefinition($paused, SerialHandler::class, startsPaused: true),
            ]))]),
            AppIdCollection::fromIds([$running, $paused]),
            $logger,
            ['shutdown_grace' => '1s'],
        );
        $ready = $logger->waitForMessage('Worker ready');

        /** @var Future<null> $run */
        $run = async($broker->lifecycle->run(...));
        $ready->await(new TimeoutCancellation(self::WAIT_SECONDS));

        foreach (['2', '3'] as $value) {
            $called = $session->waitForNextCall();
            $this->changeSerial($session, '1', $value);
            $called->await(new TimeoutCancellation(self::WAIT_SECONDS));
        }

        self::assertSame([['value' => '2'], ['value' => '3']], array_map(static fn(ReceivedServiceCall $call): array => $call->data, $session->receivedCalls));

        $broker->run->stop('test done');
        $run->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }

    public function testStoredPauseSurvivesBrokerRestart(): void
    {
        $appId = new AppId('serial-handler');
        $store = new InMemoryStoreBackend(SystemClock::inUtc());
        $client = new ControlClient(new FrameCodec(FrameCodec::createControlWireMapper()), new ProjectRoot(sys_get_temp_dir()), new RevoltTimers());
        $timeout = Duration::seconds(self::WAIT_SECONDS);
        $controlListen = 'unix://' . sys_get_temp_dir() . '/stw-' . bin2hex(random_bytes(4)) . '.sock';
        $control = new ControlTarget(ControlAddress::parse($controlListen), self::CONTROL_TOKEN);

        $first = $this->startBrokerWithStore($appId, $store, new FakeHaSession(), $controlListen);
        self::assertTrue($client->pauseApp($control, $appId, $timeout)->changed);
        $pause = $client->fetchSnapshot($control, $timeout)->apps[0]->pause;
        $this->stopBroker($first);

        $session = new FakeHaSession();
        $second = $this->startBrokerWithStore($appId, $store, $session, $controlListen);
        self::assertEquals($pause, $client->fetchSnapshot($control, $timeout)->apps[0]->pause);
        $this->changeSerial($session, '1', '2');

        self::assertTrue($client->resumeApp($control, $appId, $timeout)->changed);
        $called = $session->waitForNextCall();
        $this->changeSerial($session, '2', '3');
        $called->await(new TimeoutCancellation(self::WAIT_SECONDS));

        self::assertSame([['value' => '3']], array_map(static fn(ReceivedServiceCall $call): array => $call->data, $session->receivedCalls));
        $this->stopBroker($second);
    }

    private function startBrokerWithStore(AppId $appId, InMemoryStoreBackend $store, FakeHaSession $session, string $controlListen): BootedBroker
    {
        $logger = new RecordingLogger();
        $pools = WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $logger, outboxLimits: new OutboxLimits(100, 256));
        $broker = BrokerKernelFixture::boot(
            $session,
            $pools,
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition($appId, SerialHandler::class)]))]),
            AppIdCollection::fromIds([$appId]),
            $logger,
            [
                'shutdown_grace' => '1s',
                'control' => ['listen' => $controlListen, 'token' => self::CONTROL_TOKEN],
                'persistence' => ['url' => self::STORE_URL],
            ],
            new SyntheticServices()->withService(GuardedStoreBackend::class, new GuardedStoreBackend($store, StoreDsn::parse(self::STORE_URL), new StoreTiming(Duration::seconds(2), Duration::seconds(5)), $pools->clock)),
        );
        $ready = $logger->waitForMessage('Worker ready');
        $this->running = async($broker->lifecycle->run(...));
        $ready->await(new TimeoutCancellation(self::WAIT_SECONDS));

        return $broker;
    }

    private function stopBroker(BootedBroker $broker): void
    {
        $broker->run->stop('test done');
        $this->running?->await(new TimeoutCancellation(self::WAIT_SECONDS));
        $this->running = null;
    }

    private function changeSerial(FakeHaSession $session, string $from, string $to): void
    {
        $entityId = new EntityId(SerialHandler::ENTITY);

        $session->listener?->stateChanged(new StateChange($entityId, new EntityState($entityId, $from), new EntityState($entityId, $to)));
    }
}
