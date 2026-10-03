<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use Amp\CancelledException;
use Amp\NullCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Container\AppContainerBuilder;
use Stewart\Runtime\Container\AppOptionResolver;
use Stewart\Runtime\Ipc\Message\AppFailed;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Container\AppRuntimeServicesFixture;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsScalar;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsUnregisteredService;
use Stewart\Runtime\Tests\Fixtures\Container\TypedOptionsApp;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Lifecycle\BreaksOnDispose;
use Stewart\Runtime\Tests\Fixtures\Lifecycle\BreaksOnInitialize;
use Stewart\Runtime\Tests\Fixtures\Lifecycle\CancelsOnInitialize;
use Stewart\Runtime\Tests\Fixtures\Lifecycle\GatedDisposer;
use Stewart\Runtime\Tests\Fixtures\Lifecycle\GatedInitializer;
use Stewart\Runtime\Tests\Fixtures\Lifecycle\Healthy;
use Stewart\Runtime\Tests\Fixtures\Lifecycle\SchedulesOnConstruct;
use Stewart\Runtime\Tests\Fixtures\Lifecycle\SubscribesThenWaits;
use Stewart\Runtime\Tests\Fixtures\Worker\AppResourcesFixture;
use Stewart\Runtime\Worker\AppActivityCounters;
use Stewart\Runtime\Worker\AppFailureReporter;
use Stewart\Runtime\Worker\AppLifecycle;
use Stewart\Runtime\Worker\HandlerFailureSampler;
use Stewart\Runtime\Worker\StderrFallback;
use Stewart\Support\Text\ClosestNameFinder;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

use function Amp\async;

#[CoversClass(AppLifecycle::class)]
#[CoversClass(AppFailureReporter::class)]
final class AppLifecycleTest extends TestCase
{
    private NullTransport $transport;

    private ManualTimers $timers;

    private AppResourcesFixture $resources;

    protected function setUp(): void
    {
        $this->transport = new NullTransport();
        $this->timers = new ManualTimers();
        $this->resources = new AppResourcesFixture($this->timers);

        GatedInitializer::reset();
        GatedDisposer::reset();
        SubscribesThenWaits::reset();
        SchedulesOnConstruct::reset();
    }

    protected function tearDown(): void
    {
        GatedInitializer::open();
        GatedDisposer::open();
        SubscribesThenWaits::open();
        EventLoopTicks::settle();
    }

    public function testBrokenServicesFileFailsEveryApp(): void
    {
        $lifecycle = $this->createLifecycle([
            new WorkerApp(id: new AppId('broken'), class: Healthy::class, options: []),
            new WorkerApp(id: new AppId('healthy'), class: Healthy::class, options: []),
        ], servicesFile: __DIR__ . '/missing-services.php');

        $lifecycle->constructApps();
        $lifecycle->initializeApps();

        $failures = array_values(array_filter($this->transport->sent, static fn(object $m): bool => $m instanceof AppFailed));

        self::assertSame([], $lifecycle->listAppIdsInState(AppState::Running)->toStrings());
        self::assertSame(['broken', 'healthy'], $lifecycle->listAppIdsInState(AppState::Failed)->toStrings());
        self::assertSame(['broken', 'healthy'], array_map(static fn(AppFailed $f): string => (string) $f->scope, $failures));
        self::assertSame([AppFailurePhase::Construct, AppFailurePhase::Construct], array_map(static fn(AppFailed $f): AppFailurePhase => $f->phase, $failures));
    }

    public function testUnwirableAppFailsWithoutItsNeighbours(): void
    {
        $lifecycle = $this->createLifecycle([
            new WorkerApp(id: new AppId('unwirable'), class: NeedsUnregisteredService::class, options: []),
            new WorkerApp(id: new AppId('scalar'), class: NeedsScalar::class, options: []),
            new WorkerApp(id: new AppId('healthy'), class: Healthy::class, options: []),
        ]);

        $lifecycle->constructApps();
        $lifecycle->initializeApps();

        self::assertSame(['healthy'], $lifecycle->listAppIdsInState(AppState::Running)->toStrings());
        self::assertSame(['unwirable', 'scalar'], $lifecycle->listAppIdsInState(AppState::Failed)->toStrings());
    }

    public function testInvalidOptionFailsOnlyThatApp(): void
    {
        $lifecycle = $this->createLifecycle([
            new WorkerApp(id: new AppId('typed'), class: TypedOptionsApp::class, options: ['retries' => 'abc']),
            new WorkerApp(id: new AppId('healthy'), class: Healthy::class, options: []),
        ]);

        $lifecycle->constructApps();
        $lifecycle->initializeApps();

        self::assertSame(['healthy'], $lifecycle->listAppIdsInState(AppState::Running)->toStrings());
        self::assertSame(['typed'], $lifecycle->listAppIdsInState(AppState::Failed)->toStrings());
        self::assertSame(AppFailurePhase::Construct, $this->getOnlyFailure()->phase);
    }

    public function testInitializeFailureIsReportedAndReleased(): void
    {
        $lifecycle = $this->createLifecycle([
            new WorkerApp(id: new AppId('broken'), class: BreaksOnInitialize::class, options: []),
            new WorkerApp(id: new AppId('healthy'), class: Healthy::class, options: []),
        ]);

        $lifecycle->constructApps();
        $lifecycle->initializeApps();

        self::assertSame(['healthy'], $lifecycle->listAppIdsInState(AppState::Running)->toStrings());
        self::assertSame(['broken'], $lifecycle->listAppIdsInState(AppState::Failed)->toStrings());
        self::assertSame(AppFailurePhase::Initialize, $this->getOnlyFailure()->phase);
        self::assertSame(0, $this->resources->schedules->count());
        self::assertSame(0, $this->resources->dispatcher->countFor(ResourceScope::forApp(new AppId('broken'))));
    }

    /** @return iterable<string, array{?Duration}> */
    public static function provideInitializeTimeouts(): iterable
    {
        yield 'timeout off' => [null];
        yield 'timeout on' => [Duration::seconds(60)];
    }

    #[DataProvider('provideInitializeTimeouts')]
    public function testAppsOwnCancellationIsNotReportedAsATimeout(?Duration $initializeTimeout): void
    {
        $lifecycle = $this->createLifecycle(new WorkerApp(id: new AppId('cancels'), class: CancelsOnInitialize::class, options: []), $initializeTimeout);

        $lifecycle->constructApps();
        $lifecycle->initializeApps();

        self::assertSame(['cancels'], $lifecycle->listAppIdsInState(AppState::Failed)->toStrings());
        self::assertSame(CancelledException::class, $this->getOnlyFailure()->class);
    }

    public function testConstructorScheduleWaitsForInitialize(): void
    {
        SchedulesOnConstruct::$runs = 0;

        $lifecycle = $this->createLifecycle(new WorkerApp(id: new AppId('scheduled'), class: SchedulesOnConstruct::class, options: []));
        $lifecycle->constructApps();

        $this->timers->delay(Duration::minutes(1));
        self::assertSame(0, SchedulesOnConstruct::$runs);

        $lifecycle->initializeApps();
        $this->timers->delay(Duration::minutes(1));

        self::assertSame(6, SchedulesOnConstruct::$runs);
    }

    public function testStoppingBeforeInitializeLeavesEveryAppDormant(): void
    {
        $lifecycle = $this->createLifecycle(new WorkerApp(id: new AppId('healthy'), class: Healthy::class, options: []));

        $lifecycle->constructApps();
        $lifecycle->markStopping();

        $lifecycle->initializeApps();

        self::assertSame(['healthy'], $lifecycle->listAppIdsInState(AppState::Skipped)->toStrings(), 'A stopping worker initializes nothing.');
        self::assertSame([], $this->transport->sent);
    }

    public function testInitializeTimeoutFailsOnlyThatApp(): void
    {
        $lifecycle = $this->createLifecycle(
            [new WorkerApp(id: new AppId('slow'), class: GatedInitializer::class, options: []), new WorkerApp(id: new AppId('healthy'), class: Healthy::class, options: [])],
            initializeTimeout: Duration::seconds(60.0),
        );

        $lifecycle->constructApps();

        $started = async($lifecycle->initializeApps(...));
        EventLoopTicks::settle();

        $this->timers->delay(Duration::seconds(60));

        $started->await();

        self::assertSame(['healthy'], $lifecycle->listAppIdsInState(AppState::Running)->toStrings());
        self::assertSame(['slow'], $lifecycle->listAppIdsInState(AppState::Failed)->toStrings());
        self::assertSame(AppFailurePhase::Initialize, $this->getOnlyFailure()->phase);
    }

    public function testTimedOutInitializerIsDisposedOnReturn(): void
    {
        $lifecycle = $this->createLifecycle(
            [new WorkerApp(id: new AppId('slow'), class: GatedInitializer::class, options: [])],
            initializeTimeout: Duration::seconds(60.0),
        );

        $lifecycle->constructApps();

        $started = async($lifecycle->initializeApps(...));
        EventLoopTicks::settle();
        $this->timers->delay(Duration::seconds(60));
        $started->await();

        GatedInitializer::open();
        EventLoopTicks::settleUntil(static fn(): bool => \count(GatedInitializer::$log) === 3);

        self::assertSame(['initialize start', 'initialize end', 'dispose'], GatedInitializer::$log);

        $lifecycle->disposeAppsBefore(new NullCancellation());

        self::assertSame(['initialize start', 'initialize end', 'dispose'], GatedInitializer::$log, 'It is disposed exactly once.');
    }

    public function testShutdownInInitializeDisposesWithoutActivating(): void
    {
        $lifecycle = $this->createLifecycle([new WorkerApp(id: new AppId('slow'), class: GatedInitializer::class, options: [])]);
        $lifecycle->constructApps();

        $started = async($lifecycle->initializeApps(...));
        EventLoopTicks::settle();

        $lifecycle->markStopping();
        GatedInitializer::open();

        self::assertNull($started->await());
        self::assertSame(['initialize start', 'initialize end', 'dispose'], GatedInitializer::$log, 'It disposes as soon as initialize() returns.');
        self::assertSame(['slow'], $lifecycle->listAppIdsInState(AppState::Disposed)->toStrings());

        $lifecycle->disposeAppsBefore(new NullCancellation());

        self::assertSame(['initialize start', 'initialize end', 'dispose'], GatedInitializer::$log);
    }

    public function testAppAbandonedAtGraceExpiryIsNeverDisposed(): void
    {
        $lifecycle = $this->createLifecycle([new WorkerApp(id: new AppId('slow'), class: GatedInitializer::class, options: [])]);
        $lifecycle->constructApps();

        $started = async($lifecycle->initializeApps(...));
        EventLoopTicks::settle();

        $lifecycle->markStopping();
        $lifecycle->abandonUnfinishedApps();
        $lifecycle->disposeAppsBefore(new NullCancellation());

        GatedInitializer::open();
        EventLoopTicks::settle();
        $started->ignore();

        self::assertSame(['initialize start', 'initialize end'], GatedInitializer::$log);
    }

    public function testNoDeadlineIsArmedWhenTheTimeoutIsDisabled(): void
    {
        $lifecycle = $this->createLifecycle([new WorkerApp(id: new AppId('healthy'), class: Healthy::class, options: [])]);

        $lifecycle->constructApps();
        $lifecycle->initializeApps();

        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testEventsDuringInitializeAreHeld(): void
    {
        $lifecycle = $this->createLifecycle([new WorkerApp(id: new AppId('waiting'), class: SubscribesThenWaits::class, options: [])]);
        $lifecycle->constructApps();

        $started = async($lifecycle->initializeApps(...));
        EventLoopTicks::settle();

        $this->dispatchHallLightChange();
        EventLoopTicks::settle();

        self::assertSame(['initialize start'], SubscribesThenWaits::$log, 'The handler waits for initialize().');

        SubscribesThenWaits::open();
        $started->await();
        EventLoopTicks::settleUntil(static fn(): bool => \count(SubscribesThenWaits::$log) === 3);

        self::assertSame(['initialize start', 'initialize end', 'handled light.hall'], SubscribesThenWaits::$log);
    }

    public function testDisposeFailureIsReportedAndReleased(): void
    {
        $lifecycle = $this->createLifecycle(new WorkerApp(id: new AppId('broken'), class: BreaksOnDispose::class, options: []));

        $lifecycle->constructApps();
        $lifecycle->initializeApps();
        $lifecycle->disposeAppsBefore(new NullCancellation());

        self::assertSame(AppFailurePhase::Dispose, $this->getOnlyFailure()->phase);
    }

    public function testSlowInitializeDoesNotDelayNeighbours(): void
    {
        $lifecycle = $this->createLifecycle([
            new WorkerApp(id: new AppId('slow'), class: GatedInitializer::class, options: []),
            new WorkerApp(id: new AppId('healthy'), class: Healthy::class, options: []),
        ]);
        $lifecycle->constructApps();

        $started = async($lifecycle->initializeApps(...));
        EventLoopTicks::settle();

        self::assertSame(['healthy'], $lifecycle->listAppIdsInState(AppState::Running)->toStrings());
        self::assertSame(['slow'], $lifecycle->listAppIdsInState(AppState::Initializing)->toStrings());
        self::assertFalse($started->isComplete(), 'Startup waits for the slowest app.');

        GatedInitializer::open();
        $started->await();

        self::assertSame(['slow', 'healthy'], $lifecycle->listAppIdsInState(AppState::Running)->toStrings());
    }

    public function testHangingDisposeDoesNotBlockNeighbours(): void
    {
        GatedInitializer::open();
        $lifecycle = $this->createLifecycle([
            new WorkerApp(id: new AppId('hanging'), class: GatedDisposer::class, options: []),
            new WorkerApp(id: new AppId('healthy'), class: GatedInitializer::class, options: []),
        ]);
        $lifecycle->constructApps();
        $lifecycle->initializeApps();

        $disposal = async(fn() => $lifecycle->disposeAppsBefore($this->timers->timeout(Duration::seconds(5))));
        EventLoopTicks::settle();

        self::assertSame(['dispose start'], GatedDisposer::$log);
        self::assertSame(['initialize start', 'initialize end', 'dispose'], GatedInitializer::$log);

        $this->timers->delay(Duration::seconds(5));

        $this->expectException(CancelledException::class);
        $disposal->await();
    }

    public function testHandlerDoesNotFireWhileAppDisposes(): void
    {
        $lifecycle = $this->createLifecycle(new WorkerApp(id: new AppId('disposing'), class: GatedDisposer::class, options: []));
        $lifecycle->constructApps();
        $lifecycle->initializeApps();

        $this->dispatchHallLightChange();
        EventLoopTicks::settleUntil(static fn(): bool => GatedDisposer::$log === ['handled light.hall']);

        $disposal = async(fn() => $lifecycle->disposeAppsBefore(new NullCancellation()));
        EventLoopTicks::settle();
        $this->dispatchHallLightChange();
        EventLoopTicks::settle();
        GatedDisposer::open();
        $disposal->await();

        self::assertSame(['handled light.hall', 'dispose start', 'dispose end'], GatedDisposer::$log);
    }

    private function dispatchHallLightChange(): void
    {
        $this->resources->dispatcher->dispatchStateChange(new StateChange(new EntityId('light.hall'), null, new EntityState(new EntityId('light.hall'), 'on')));
    }

    /** @param list<WorkerApp>|WorkerApp $apps */
    private function createLifecycle(array|WorkerApp $apps, ?Duration $initializeTimeout = null, ?string $servicesFile = null): AppLifecycle
    {
        return new AppLifecycle(
            TestBootstrap::createForApps(\is_array($apps) ? $apps : [$apps], initializeTimeout: $initializeTimeout, servicesFile: $servicesFile),
            AppRuntimeServicesFixture::createAppRuntimeServices(
                transport: $this->transport,
                resources: $this->resources,
            ),
            $this->resources->resources,
            new AppFailureReporter($this->transport, new StderrFallback(new WorkerId(0)), new AppActivityCounters(), new HandlerFailureSampler()),
            new AppContainerBuilder(new AppOptionResolver(new ClosestNameFinder())),
        );
    }

    private function getOnlyFailure(): AppFailed
    {
        $failures = array_values(array_filter($this->transport->sent, static fn(object $m): bool => $m instanceof AppFailed));

        self::assertCount(1, $failures);

        return $failures[0];
    }
}
