<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Exposure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Client\Component\ComponentEventDecoder;
use Stewart\Client\Component\ComponentInstance;
use Stewart\Client\Component\ExposedEntityDefinition;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Connection\HaConnection;
use Stewart\Client\Connection\HomeAssistantUrl;
use Stewart\Client\Event\EventDecoder;
use Stewart\Client\HaClient;
use Stewart\Client\Registry\RegistryDecoder;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\Component\ComponentTracker;
use Stewart\Runtime\Broker\Exposure\ExposureLink;
use Stewart\Runtime\Broker\Exposure\ExposureReconciler;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Websocket\FakeWebsocketConnection;
use Stewart\Testing\Websocket\FakeWebsocketConnector;

#[CoversClass(ExposureReconciler::class)]
final class ExposureReconcilerTest extends TestCase
{
    private const array RECONCILED = ['type' => 'result', 'success' => true, 'result' => ['removed' => []]];

    private ManualTimers $timers;

    private FakeWebsocketConnection $socket;

    private HaClient $client;

    private ComponentTracker $tracker;

    private ExposureLink $exposures;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $this->client = $this->createClient();
        $this->tracker = new ComponentTracker($this->timers->clock);
        $this->exposures = new ExposureLink($this->client, new NullLogger(), $this->tracker, new ExposeConfig(ComponentInstance::parse('default'), Duration::seconds(10)));
        $this->client->connect();
        $this->socket->replyWhenSent('stewart/entity/reconcile', self::RECONCILED);
    }

    public function testFailureIsRetriedOnNextConnect(): void
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $this->socket = $socket;
        $this->client = $this->createClient();
        $this->client->connect();
        $socket->replyWhenSent('stewart/entity/reconcile', ['type' => 'result', 'success' => false, 'error' => ['code' => 'no_session', 'message' => 'No session.']]);
        $reconciler = $this->createReconciler();
        $this->activateComponent();
        $this->recordEveryWorkerReady($reconciler);
        $this->timers->delay(Duration::seconds(30));
        $socket->replyWhenSent('stewart/entity/reconcile', self::RECONCILED);

        $reconciler->reconcileIfDue();

        self::assertCount(2, $this->listReconciles());
    }

    public function testWaitsForLastWorker(): void
    {
        $reconciler = $this->createReconciler();
        $this->activateComponent();

        $reconciler->recordWorkerReady(new WorkerId(0), self::listApps('climate'));
        $this->timers->delay(Duration::minutes(5));

        self::assertSame([], $this->listReconciles());
    }

    public function testRunsAfterGraceOnceWorkersAreReady(): void
    {
        $reconciler = $this->createReconciler();
        $this->activateComponent();
        $this->recordEveryWorkerReady($reconciler);

        $this->timers->delay(Duration::seconds(29));
        self::assertSame([], $this->listReconciles());

        $this->timers->delay(Duration::seconds(1));
        self::assertCount(1, $this->listReconciles());
    }

    public function testQuarantinedWorkerKeepsItsApps(): void
    {
        $reconciler = $this->createReconciler();
        $this->activateComponent();

        $reconciler->recordWorkerReady(new WorkerId(0), self::listApps('climate'));
        $reconciler->recordWorkerQuarantined(new WorkerId(1));
        $this->timers->delay(Duration::seconds(30));

        self::assertSame(['lights'], $this->listReconciles()[0]['keep_apps'] ?? null);
    }

    public function testFailedAppIsKept(): void
    {
        $reconciler = $this->createReconciler();
        $this->activateComponent();

        $reconciler->recordWorkerReady(new WorkerId(0), self::listApps());
        $reconciler->recordWorkerReady(new WorkerId(1), self::listApps('lights'));
        $this->timers->delay(Duration::seconds(30));

        self::assertSame(['climate'], $this->listReconciles()[0]['keep_apps'] ?? null);
    }

    public function testKeepsEveryKnownExposure(): void
    {
        $reconciler = $this->createReconciler();
        $this->exposures->exposeEntity(
            new WorkerId(0),
            new AppId('climate'),
            new ExposedEntityKey('average_temperature'),
            ExposedEntityDefinition::fromConfig(new SensorConfig(), null),
            new ExposedStateChange(new ExposedState(21.4)),
        );
        $this->recordEveryWorkerReady($reconciler);
        $this->timers->delay(Duration::seconds(30));
        $this->activateComponent();

        $reconciler->reconcileIfDue();

        self::assertSame([['app' => 'climate', 'key' => 'average_temperature']], $this->listReconciles()[0]['keep'] ?? null);
    }

    public function testWaitsForActiveComponent(): void
    {
        $reconciler = $this->createReconciler();
        $this->recordEveryWorkerReady($reconciler);
        $this->timers->delay(Duration::seconds(30));
        self::assertSame([], $this->listReconciles());

        $this->activateComponent();
        $reconciler->reconcileIfDue();

        self::assertCount(1, $this->listReconciles());
    }

    public function testRunsOncePerRun(): void
    {
        $reconciler = $this->createReconciler();
        $this->activateComponent();
        $this->recordEveryWorkerReady($reconciler);
        $this->timers->delay(Duration::seconds(30));

        $reconciler->reconcileIfDue();
        $reconciler->recordWorkerReady(new WorkerId(0), self::listApps('climate'));
        $this->timers->delay(Duration::seconds(30));

        self::assertCount(1, $this->listReconciles());
    }

    public function testPruneOffSendsNothing(): void
    {
        $reconciler = $this->createReconciler(prune: false);
        $this->activateComponent();
        $this->recordEveryWorkerReady($reconciler);

        $this->timers->delay(Duration::seconds(30));
        $reconciler->reconcileIfDue();

        self::assertSame([], $this->listReconciles());
    }

    public function testNoWorkersRunsAfterStartup(): void
    {
        $reconciler = new ExposureReconciler(
            WorkerSlotCollection::fromWorkerSlots([]),
            $this->exposures,
            $this->client,
            $this->tracker,
            new ExposeConfig(ComponentInstance::parse('default'), Duration::seconds(10)),
            $this->timers,
            new NullLogger(),
        );
        $this->activateComponent();

        $reconciler->scheduleOnceWorkersSettle();
        $this->timers->delay(Duration::seconds(30));

        self::assertSame([], $this->listReconciles()[0]['keep_apps'] ?? null);
    }

    private function createReconciler(bool $prune = true): ExposureReconciler
    {
        return new ExposureReconciler(
            WorkerSlotCollection::fromWorkerSlots([self::createSlot(0, 'climate'), self::createSlot(1, 'lights')]),
            $this->exposures,
            $this->client,
            $this->tracker,
            new ExposeConfig(ComponentInstance::parse('default'), Duration::seconds(10), $prune),
            $this->timers,
            new NullLogger(),
        );
    }

    private function recordEveryWorkerReady(ExposureReconciler $reconciler): void
    {
        $reconciler->recordWorkerReady(new WorkerId(0), self::listApps('climate'));
        $reconciler->recordWorkerReady(new WorkerId(1), self::listApps('lights'));
    }

    private function activateComponent(): void
    {
        $this->tracker->recordState(ComponentState::Active, null);
    }

    /** @return list<array<string, mixed>> */
    private function listReconciles(): array
    {
        return $this->socket->listSentOfType('stewart/entity/reconcile');
    }

    private static function createSlot(int $workerId, string $appId): WorkerSlot
    {
        return new WorkerSlot(new WorkerId($workerId), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId($appId), Demo::class)]));
    }

    private static function listApps(string ...$appIds): AppIdCollection
    {
        return AppIdCollection::fromIds(array_map(static fn(string $appId): AppId => new AppId($appId), $appIds));
    }

    private function createClient(): HaClient
    {
        $connection = new HaConnection(
            new ConnectionConfig(
                url: HomeAssistantUrl::parse('http://home-assistant.invalid:8123'),
                token: 'test-token',
                connectTimeout: Duration::seconds(1),
                commandTimeout: Duration::seconds(1),
                heartbeatInterval: null,
            ),
            $this->timers,
            new NullLogger(),
            new FakeWebsocketConnector($this->socket),
        );

        return new HaClient($connection, new EventDecoder(new EntityStateDecoder()), new EntityStateDecoder(), new RegistryDecoder(), new ComponentEventDecoder(), new NullLogger());
    }
}
