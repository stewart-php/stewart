<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Store\Store;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\GeneratedRoots;
use Stewart\Runtime\Container\AppBuildFailure;
use Stewart\Runtime\Container\AppContainerBuild;
use Stewart\Runtime\Container\AppContainerBuilder;
use Stewart\Runtime\Container\AppOptionResolver;
use Stewart\Runtime\Container\AppRuntimeServices;
use Stewart\Runtime\Container\Collection\AppBuildFailureCollection;
use Stewart\Runtime\Container\TypedContainer;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Exception\ContainerError;
use Stewart\Runtime\Ipc\Collection\WorkerAppCollection;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Schedule\WorkerTimers;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Apps\Nested\HallLight;
use Stewart\Runtime\Tests\Fixtures\Container\AppRuntimeServicesFixture;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsAttributedStores;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsAutowiredScalar;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsClock;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsGenerated;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsMissingClass;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsNullableScalar;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsScalar;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsStewartIdentity;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsStores;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsSunCalendar;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsUnknownPeer;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsUnregisteredService;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsWritablePeer;
use Stewart\Runtime\Tests\Fixtures\Container\ScalarConsumer;
use Stewart\Runtime\Tests\Fixtures\Container\SharedStoreConsumer;
use Stewart\Runtime\Tests\Fixtures\Container\TimerConsumer;
use Stewart\Runtime\Tests\Fixtures\Container\TypedOptionsApp;
use Stewart\Runtime\Tests\Fixtures\Generated\Code\Entities;
use Stewart\Runtime\Tests\Fixtures\Generated\Code\Services as GeneratedServices;
use Stewart\Runtime\Tests\Fixtures\Generated\GeneratedSet;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Tests\Fixtures\Worker\AppResourcesFixture;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\PendingCalls;
use Stewart\Runtime\Worker\WorkerHaContext;
use Stewart\Runtime\Worker\WorkerLogger;
use Stewart\Store\DisabledStores;
use Stewart\Store\KeyspacedStores;
use Stewart\Store\ReadOnlyStore;
use Stewart\Store\ScopedStore;
use Stewart\Store\StorePrefix;
use Stewart\Store\Stores;
use Stewart\Store\StoreValueCodec;
use Stewart\Support\Text\ClosestNameFinder;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Store\InMemoryStoreBackend;
use Stewart\Testing\Time\VirtualClock;
use Throwable;

#[CoversClass(AppContainerBuilder::class)]
#[CoversClass(AppRuntimeServices::class)]
#[CoversClass(TypedContainer::class)]
#[CoversClass(AppOptionResolver::class)]
#[CoversClass(AppContainerBuild::class)]
#[CoversClass(AppBuildFailure::class)]
#[CoversClass(AppBuildFailureCollection::class)]
#[CoversClass(GeneratedRoots::class)]
final class AppContainerBuilderTest extends TestCase
{
    use AssertsReason;

    private const string SERVICES_FILE = __DIR__ . '/../../Fixtures/Container/services/shared-store.php';

    private const string DEFINES_APP = __DIR__ . '/../../Fixtures/Container/services/defines-app.php';

    private const string DEFAULTS = __DIR__ . '/../../Fixtures/Container/services/defaults-binding.php';

    private const string TIMER_USER = __DIR__ . '/../../Fixtures/Container/services/timer-user.php';

    private const string UNWIRABLE_SERVICE = __DIR__ . '/../../Fixtures/Container/services/unwirable-service.php';

    private StateCache $stateCache;

    private Stores $stores;

    private AppResourcesFixture $resources;

    private NullTransport $transport;

    protected function setUp(): void
    {
        $this->resources = new AppResourcesFixture();
        $this->transport = new NullTransport();
    }

    public function testAppThatAsksForAStoreGetsItsOwnScope(): void
    {
        $this->stores = $this->createBackedStores();

        $services = $this->buildContainer([
            new WorkerApp(id: new AppId('demo'), class: NeedsStores::class, options: []),
            new WorkerApp(id: new AppId('hall-light'), class: NeedsStores::class, options: []),
        ]);

        $demo = $this->getApp($services, 'demo', NeedsStores::class);
        $hall = $this->getApp($services, 'hall-light', NeedsStores::class);

        $demo->store->set('mode', 'away');

        self::assertNull($hall->store->getString('mode'), 'One automation\'s keys are its own.');
        self::assertSame('away', $demo->store->getString('mode'));
    }

    public function testServiceOfTheUsersOwnGetsTheSharedScope(): void
    {
        $this->stores = $this->createBackedStores();

        $services = $this->buildContainer([new WorkerApp(id: new AppId('demo'), class: NeedsStores::class, options: [])], self::SERVICES_FILE);

        $shared = $services->resolveService(SharedStoreConsumer::class);
        $shared->store->set('house_mode', 'night');

        self::assertSame('night', $this->stores->openGlobalStore()->getString('house_mode'));
        self::assertNull($this->getApp($services, 'demo', NeedsStores::class)->store->getString('house_mode'));
    }

    public function testWithoutPersistenceOnlyStoreUsersFail(): void
    {
        $services = $this->buildContainer([
            new WorkerApp(id: new AppId('demo'), class: Demo::class, options: []),
            new WorkerApp(id: new AppId('keeper'), class: NeedsStores::class, options: []),
        ]);

        $this->getApp($services, 'demo', Demo::class);

        try {
            $this->getApp($services, 'keeper', NeedsStores::class);
            self::fail('Without persistence there is no store to hand out.');
        } catch (Throwable $e) {
            self::assertStringContainsString('persistence.url', self::findRootCause($e)->getMessage());
        }
    }

    public function testEachAppGetsItsOwnContext(): void
    {
        $services = $this->buildContainer([
            new WorkerApp(id: new AppId('demo'), class: Demo::class, options: []),
            new WorkerApp(id: new AppId('hall-light'), class: HallLight::class, options: []),
        ]);

        $demo = $this->getApp($services, 'demo', Demo::class);
        $hall = $this->getApp($services, 'hall-light', HallLight::class);

        self::assertNotSame($demo->ha, $hall->ha);
    }

    public function testLateSnapshotIsVisibleToApps(): void
    {
        $services = $this->buildContainer([
            new WorkerApp(id: new AppId('demo'), class: Demo::class, options: []),
            new WorkerApp(id: new AppId('hall-light'), class: HallLight::class, options: []),
        ]);

        $demo = $this->getApp($services, 'demo', Demo::class);
        $hall = $this->getApp($services, 'hall-light', HallLight::class);

        $this->stateCache->replaceAll(EntityStateCollection::keyedByEntityId([new EntityState(new EntityId('light.hall'), 'on')]));

        $seenByDemo = $demo->ha->getState('light.hall');
        $seenByHall = $hall->ha->getState('light.hall');

        self::assertNotNull($seenByDemo);
        self::assertNotNull($seenByHall);
        self::assertSame('on', $seenByDemo->state);
        self::assertSame('on', $seenByHall->state);
    }

    public function testAppsAreBuiltBeforeThereIsAnyStateToRead(): void
    {
        $services = $this->buildContainer([new WorkerApp(id: new AppId('demo'), class: Demo::class, options: [])]);

        self::assertNull($this->getApp($services, 'demo', Demo::class)->ha->getState('light.hall'));
    }

    /** @return iterable<string, array{class-string}> */
    public static function provideInternalServiceClasses(): iterable
    {
        foreach ([
            Transport::class,
            StateCache::class,
            LocalDispatcher::class,
            PendingCalls::class,
            CorrelationIdSequence::class,
            WorkerLogger::class,
            WorkerHaContext::class,
            Stores::class,
            ScopedStore::class,
            ReadOnlyStore::class,
        ] as $class) {
            yield $class => [$class];
        }
    }

    /** @param class-string $class */
    #[DataProvider('provideInternalServiceClasses')]
    public function testRuntimeInternalsAreNotInTheContainer(string $class): void
    {
        $services = $this->buildContainer([new WorkerApp(id: new AppId('demo'), class: Demo::class, options: [])]);

        $this->expectException(NotFoundExceptionInterface::class);

        $services->resolveService($class);
    }

    public function testAppReadsItsOwnScopeThroughAReadOnlyStore(): void
    {
        $this->stores = $this->createBackedStores();

        $services = $this->buildContainer([new WorkerApp(id: new AppId('demo'), class: NeedsStores::class, options: [])]);
        $demo = $this->getApp($services, 'demo', NeedsStores::class);

        $demo->store->set('mode', 'away');

        self::assertInstanceOf(ReadOnlyStore::class, $demo->view);
        self::assertSame('away', $demo->view->getString('mode'));
    }

    public function testUserServiceReadsSharedScopeReadOnly(): void
    {
        $this->stores = $this->createBackedStores();
        $this->stores->openGlobalStore()->set('house_mode', 'night');
        $this->stores->openAppStore(new AppId('demo'))->set('house_mode', 'away');

        $services = $this->buildContainer([], self::SERVICES_FILE);
        $shared = $services->resolveService(SharedStoreConsumer::class);

        self::assertInstanceOf(ReadOnlyStore::class, $shared->view);
        self::assertSame('night', $shared->view->getString('house_mode'));
        self::assertSame('away', $shared->demo->getString('house_mode'), 'A peer attribute works outside automations too.');
    }

    public function testGlobalStoreAttributeBeatsBinding(): void
    {
        $this->stores = $this->createBackedStores();

        $keeper = $this->getApp($this->buildContainer([new WorkerApp(id: new AppId('keeper'), class: NeedsAttributedStores::class, options: [])]), 'keeper', NeedsAttributedStores::class);

        $keeper->shared->set('house_mode', 'night');

        self::assertSame('night', $this->stores->openGlobalStore()->getString('house_mode'));
        self::assertNull($this->stores->openAppStore(new AppId('keeper'))->getString('house_mode'));
        self::assertInstanceOf(ReadOnlyStore::class, $keeper->sharedView);
        self::assertSame('night', $keeper->sharedView->getString('house_mode'));
    }

    public function testPeekedScopeIsAnotherAppsAndCannotBeWrittenTo(): void
    {
        $this->stores = $this->createBackedStores();
        $this->stores->openAppStore(new AppId('boiler'))->set('target', 60.0);

        $keeper = $this->getApp($this->buildContainer([new WorkerApp(id: new AppId('keeper'), class: NeedsAttributedStores::class, options: [])]), 'keeper', NeedsAttributedStores::class);

        self::assertNotInstanceOf(Store::class, $keeper->peer);
        self::assertSame(60.0, $keeper->peer->getFloat('target'));
    }

    public function testWritablePeerFailsOnlyItsApp(): void
    {
        $this->stores = $this->createBackedStores();

        $build = $this->buildAppContainer([
            new WorkerApp(id: new AppId('keeper'), class: NeedsWritablePeer::class, options: []),
            new WorkerApp(id: new AppId('demo'), class: Demo::class, options: []),
        ]);

        self::assertStringContainsString('type it ReadableStore', $this->findFailure($build, 'keeper', ContainerError::PeerStoreWritable)->getMessage());
        self::assertInstanceOf(Demo::class, $this->getApp($build->container, 'demo', Demo::class));
    }

    public function testOptionsArriveAsNamedConstructorArguments(): void
    {
        $services = $this->buildContainer([
            new WorkerApp(id: new AppId('demo'), class: Demo::class, options: ['watch' => 'light.porch']),
        ]);

        $demo = $this->getApp($services, 'demo', Demo::class);

        self::assertSame('light.porch', $demo->watch);
        self::assertNull($demo->light);
    }

    public function testStringOptionsCoerceToParameterTypes(): void
    {
        $services = $this->buildContainer([
            new WorkerApp(id: new AppId('typed'), class: TypedOptionsApp::class, options: ['enabled' => 'yes', 'retries' => ' 3', 'ratio' => '0.5', 'label' => 'on']),
        ]);

        $app = $this->getApp($services, 'typed', TypedOptionsApp::class);

        self::assertTrue($app->enabled);
        self::assertSame(3, $app->retries);
        self::assertSame(0.5, $app->ratio);
        self::assertSame('on', $app->label, 'A string option keeps its spelling.');
    }

    public function testUnreadableOptionFailsOnlyItsOwnApp(): void
    {
        $build = $this->buildAppContainer([
            new WorkerApp(id: new AppId('typed'), class: TypedOptionsApp::class, options: ['enabled' => 'maybe']),
            new WorkerApp(id: new AppId('demo'), class: Demo::class, options: []),
        ]);

        self::assertSame(ContainerError::AppOptionInvalid, $build->failures->find(new AppId('typed'))?->error->reason);
        self::assertNull($build->failures->find(new AppId('demo')));
        self::assertInstanceOf(Demo::class, $this->getApp($build->container, 'demo', Demo::class));
    }

    public function testVanishedServicesFileFailsTheBuild(): void
    {
        $this->assertThrowsReason(ContainerError::ServicesFileMissing, fn() => $this->buildContainer([], __DIR__ . '/missing-services.php'));
    }

    public function testOptionContainingAPercentSignSurvives(): void
    {
        $services = $this->buildContainer([
            new WorkerApp(id: new AppId('demo'), class: Demo::class, options: ['watch' => 'a %s and 50%']),
        ]);

        $demo = $this->getApp($services, 'demo', Demo::class);

        self::assertSame('a %s and 50%', $demo->watch);
    }

    public function testAppCanAutowireItsOwnLogger(): void
    {
        $services = $this->buildContainer([new WorkerApp(id: new AppId('hall-light'), class: HallLight::class, options: [])]);
        $hall = $this->getApp($services, 'hall-light', HallLight::class);

        self::assertInstanceOf(WorkerLogger::class, $hall->logger);
    }

    public function testAppCanAutowireTheClockAndTimers(): void
    {
        $services = $this->buildContainer([new WorkerApp(id: new AppId('demo'), class: NeedsClock::class, options: [])]);
        $demo = $this->getApp($services, 'demo', NeedsClock::class);

        self::assertInstanceOf(VirtualClock::class, $demo->clock);
        self::assertInstanceOf(WorkerTimers::class, $demo->timers);
    }

    public function testAppCanAutowireStewartIdentity(): void
    {
        $services = $this->buildContainer([new WorkerApp(id: new AppId('demo'), class: NeedsStewartIdentity::class, options: [])]);

        self::assertSame(AppRuntimeServicesFixture::STEWART_USER_ID, $this->getApp($services, 'demo', NeedsStewartIdentity::class)->identity->haUserId);
    }

    public function testAppCanAutowireSunCalendar(): void
    {
        $services = $this->buildContainer([new WorkerApp(id: new AppId('demo'), class: NeedsSunCalendar::class, options: [])]);

        self::assertSame(AppRuntimeServicesFixture::LATITUDE, $this->getApp($services, 'demo', NeedsSunCalendar::class)->sunCalendar->getLocation()->latitude);
    }

    public function testAppTimersBelongToTheAppsScope(): void
    {
        $demo = $this->buildSingleApp('demo', NeedsClock::class);

        $demo->timers->startTimer(Duration::seconds(5), static function (): void {});

        self::assertSame(1, $this->resources->schedules->countFor(ResourceScope::forApp(new AppId('demo'))));
        self::assertSame(0, $this->resources->timers->countPendingTimers(), 'It waits for the app to go live.');
    }

    public function testUserServiceTimersBelongToSharedScope(): void
    {
        $services = $this->buildContainer([], self::TIMER_USER);

        $services->resolveService(TimerConsumer::class)->timers->startTimer(Duration::seconds(5), static function (): void {});

        self::assertSame(1, $this->resources->schedules->countFor(ResourceScope::shared()));
    }

    public function testUnknownOptionFailsOnlyItsApp(): void
    {
        $build = $this->buildAppContainer([
            new WorkerApp(id: new AppId('demo'), class: Demo::class, options: ['wtach' => 'typo']),
            new WorkerApp(id: new AppId('hall-light'), class: HallLight::class, options: []),
        ]);

        self::assertStringEndsWith('Did you mean "watch"?', $this->findFailure($build, 'demo', ContainerError::AppOptionUnknown)->getMessage());
        self::assertInstanceOf(HallLight::class, $this->getApp($build->container, 'hall-light', HallLight::class));
    }

    public function testMissingRequiredOptionFailsOnlyItsApp(): void
    {
        $build = $this->buildAppContainer([
            new WorkerApp(id: new AppId('scalar'), class: NeedsScalar::class, options: []),
            new WorkerApp(id: new AppId('demo'), class: Demo::class, options: []),
        ]);

        self::assertStringContainsString('$entity', $this->findFailure($build, 'scalar', ContainerError::AppOptionMissing)->getMessage());
        self::assertInstanceOf(Demo::class, $this->getApp($build->container, 'demo', Demo::class));
    }

    public function testVanishedAppClassFailsOnlyItsApp(): void
    {
        $build = $this->buildAppContainer([
            new WorkerApp(id: new AppId('gone'), class: 'Stewart\\Runtime\\Tests\\Fixtures\\Container\\NoSuchApp', options: []),
            new WorkerApp(id: new AppId('demo'), class: Demo::class, options: []),
        ]);

        $this->findFailure($build, 'gone', ContainerError::AppClassMissing);
        self::assertInstanceOf(Demo::class, $this->getApp($build->container, 'demo', Demo::class));
    }

    public function testNullableScalarStillNeedsAnOption(): void
    {
        $build = $this->buildAppContainer([new WorkerApp(id: new AppId('nullable'), class: NeedsNullableScalar::class, options: [])]);

        $this->findFailure($build, 'nullable', ContainerError::AppOptionMissing);
    }

    public function testAutowiredScalarNeedsNoOption(): void
    {
        $app = $this->getApp($this->buildContainer([new WorkerApp(id: new AppId('wired'), class: NeedsAutowiredScalar::class, options: [])]), 'wired', NeedsAutowiredScalar::class);

        self::assertSame(0, $app->workerId);
    }

    public function testUnbuildableAppFailsWhileOthersRun(): void
    {
        $build = $this->buildAppContainer([
            new WorkerApp(id: new AppId('demo'), class: Demo::class, options: []),
            new WorkerApp(id: new AppId('unregistered'), class: NeedsUnregisteredService::class, options: []),
        ]);

        $failure = $this->findFailure($build, 'unregistered', ContainerError::AppBuildFailed);

        self::assertSame(['appId' => 'unregistered'], $failure->context);
        self::assertStringContainsString('RecordingPoolListener', $failure->getMessage());
        self::assertInstanceOf(Demo::class, $this->getApp($build->container, 'demo', Demo::class));
    }

    public function testIsolatedAppsAreNamedInOneWarning(): void
    {
        $this->buildAppContainer([
            new WorkerApp(id: new AppId('demo'), class: Demo::class, options: []),
            new WorkerApp(id: new AppId('unregistered'), class: NeedsUnregisteredService::class, options: []),
            new WorkerApp(id: new AppId('missing'), class: NeedsMissingClass::class, options: []),
        ]);

        $warnings = array_values(array_filter($this->transport->sent, static fn(object $message): bool => $message instanceof LogRecord && $message->level === LogLevel::Warning));

        self::assertCount(1, $warnings);
        self::assertInstanceOf(LogRecord::class, $warnings[0]);
        self::assertSame(['apps' => ['unregistered', 'missing']], $warnings[0]->context);
    }

    public function testUnwirableUserServiceFailsTheWholeWorker(): void
    {
        $e = $this->assertThrowsReason(ContainerError::BuildFailed, fn() => $this->buildAppContainer([new WorkerApp(id: new AppId('demo'), class: Demo::class, options: [])], self::UNWIRABLE_SERVICE));

        self::assertSame(['workerId' => 0], $e->context);
        self::assertStringContainsString('$entity', $e->getMessage());
    }

    public function testGeneratedRootsReachAnAppThroughItsOwnContext(): void
    {
        $services = $this->buildContainer([
            new WorkerApp(id: new AppId('demo'), class: NeedsGenerated::class, options: []),
            new WorkerApp(id: new AppId('hall-light'), class: NeedsGenerated::class, options: []),
        ], generated: GeneratedRoots::fromNamespace(GeneratedSet::NAMESPACE));

        $demo = $this->getApp($services, 'demo', NeedsGenerated::class);
        $hall = $this->getApp($services, 'hall-light', NeedsGenerated::class);

        self::assertNotSame($demo->entities, $hall->entities);

        $this->stateCache->replaceAll(EntityStateCollection::keyedByEntityId([new EntityState(new EntityId('light.hall'), 'on', ['brightness' => 200])]));

        self::assertSame(200.0, $demo->entities->light->getEntity('light.hall')->getState()?->getBrightness());
        self::assertSame('light.hall', (string) $demo->entities->light->getEntity('light.hall')->getEntity()->id);
    }

    public function testSharedAliasExistsForServicesOfTheUsersOwn(): void
    {
        $services = $this->buildContainer([], generated: GeneratedRoots::fromNamespace(GeneratedSet::NAMESPACE));

        self::assertInstanceOf(Entities::class, $services->resolveService(Entities::class));
        self::assertInstanceOf(GeneratedServices::class, $services->resolveService(GeneratedServices::class));
    }

    public function testMissingGeneratedCodeFailsOnlyItsApp(): void
    {
        $build = $this->buildAppContainer([
            new WorkerApp(id: new AppId('missing'), class: NeedsMissingClass::class, options: []),
            new WorkerApp(id: new AppId('demo'), class: Demo::class, options: []),
        ]);

        self::assertStringContainsString('NotGeneratedYet', $this->findFailure($build, 'missing', ContainerError::AppBuildFailed)->getMessage());
        self::assertInstanceOf(Demo::class, $this->getApp($build->container, 'demo', Demo::class));
    }

    public function testOptionReachesAScalarParameter(): void
    {
        $lamp = $this->buildSingleApp('lamp', NeedsScalar::class, options: ['entity' => 'light.from_yaml']);

        self::assertSame('light.from_yaml', $lamp->entity);
    }

    public function testAutomationDefinedInServicesPhpFailsTheBuild(): void
    {
        $e = $this->assertThrowsReason(ContainerError::AppInServicesFile, fn() => $this->buildSingleApp('lamp', NeedsScalar::class, self::DEFINES_APP, ['entity' => 'light.from_yaml']));

        self::assertStringContainsString(NeedsScalar::class, $e->getMessage());
    }

    public function testServicesFileDefaultsSkipApps(): void
    {
        $services = $this->buildContainer([], self::DEFAULTS);

        self::assertSame('light.from_defaults', $services->resolveService(ScalarConsumer::class)->entity);

        $build = $this->buildAppContainer([new WorkerApp(id: new AppId('lamp'), class: NeedsScalar::class, options: [])], self::DEFAULTS);

        self::assertStringContainsString('$entity', $this->findFailure($build, 'lamp', ContainerError::AppOptionMissing)->getMessage());
    }

    public function testUnknownPeerFailsAppConstruction(): void
    {
        $this->stores = $this->createBackedStores();
        $services = $this->buildContainer([new WorkerApp(id: new AppId('keeper'), class: NeedsUnknownPeer::class, options: [])]);

        try {
            $this->getApp($services, 'keeper', App::class);
            self::fail('There is no automation called "boilr" to read.');
        } catch (Throwable $e) {
            self::assertStringContainsString('Did you mean "boiler"?', self::findRootCause($e)->getMessage());
        }
    }

    /** @param list<WorkerApp> $apps */
    private function buildContainer(array $apps, ?string $servicesFile = null, ?GeneratedRoots $generated = null): TypedContainer
    {
        $build = $this->buildAppContainer($apps, $servicesFile, $generated);

        self::assertSame(0, $build->failures->count());

        return $build->container;
    }

    /** @param list<WorkerApp> $apps */
    private function buildAppContainer(array $apps, ?string $servicesFile = null, ?GeneratedRoots $generated = null): AppContainerBuild
    {
        return new AppContainerBuilder(new AppOptionResolver(new ClosestNameFinder()))->buildAppContainer(WorkerAppCollection::fromApps($apps), $this->createRuntimeServices($generated), $servicesFile, new WorkerId(0));
    }

    private function createRuntimeServices(?GeneratedRoots $generated = null): AppRuntimeServices
    {
        $this->stateCache ??= new StateCache();
        $this->stores ??= new DisabledStores();

        return AppRuntimeServicesFixture::createAppRuntimeServices(transport: $this->transport, stateCache: $this->stateCache, resources: $this->resources, stores: $this->stores, generated: $generated);
    }

    /**
     * @template T of App
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function getApp(TypedContainer $services, string $id, string $class): App
    {
        return $services->resolveService($class, AppContainerBuilder::buildAppServiceId(new AppId($id)));
    }

    /**
     * @template T of App
     *
     * @param class-string<T> $class
     * @param array<string, mixed> $options
     * @return T
     */
    private function buildSingleApp(string $id, string $class, ?string $servicesFile = null, array $options = []): App
    {
        return $this->getApp($this->buildContainer([new WorkerApp(id: new AppId($id), class: $class, options: $options)], $servicesFile), $id, $class);
    }

    /** @return StewartException<ExceptionReason> */
    private function findFailure(AppContainerBuild $build, string $appId, ContainerError $reason): StewartException
    {
        $failure = $build->failures->find(new AppId($appId));

        self::assertNotNull($failure, \sprintf('App "%s" was expected to fail.', $appId));
        self::assertSame($reason, $failure->error->reason);

        return $failure->error;
    }

    private function createBackedStores(): Stores
    {
        return new KeyspacedStores(new InMemoryStoreBackend(new VirtualClock()), new StorePrefix('stewart'), AppIdCollection::fromIds([new AppId('demo'), new AppId('hall-light'), new AppId('keeper'), new AppId('boiler')]), new StoreValueCodec(), new ClosestNameFinder());
    }

    private static function findRootCause(Throwable $e): Throwable
    {
        while ($e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return $e;
    }
}
