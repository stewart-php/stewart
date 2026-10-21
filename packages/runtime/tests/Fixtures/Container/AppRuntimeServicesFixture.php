<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Psr\Log\NullLogger;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Container\AppRuntimeServices;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Logging\LogVerbosityPolicy;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Schedule\WorkerSchedulerFixture;
use Stewart\Runtime\Tests\Fixtures\Worker\AppResourcesFixture;
use Stewart\Runtime\Tests\Fixtures\Worker\WorkerHaContextFixture;
use Stewart\Runtime\Worker\AppActivityCounters;
use Stewart\Runtime\Worker\ConnectionStatus;
use Stewart\Runtime\Worker\Context\DispatchStreams;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\Exposure\ExposedCommandSettlements;
use Stewart\Runtime\Worker\Exposure\ExposedCommandStreams;
use Stewart\Runtime\Worker\Exposure\ExposureChannel;
use Stewart\Runtime\Worker\Exposure\ExposureRequester;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Runtime\Worker\Registry\RegistryEditRequester;
use Stewart\Runtime\Worker\StderrFallback;
use Stewart\Runtime\Worker\WorkerEntityExposure;
use Stewart\Runtime\Worker\WorkerLogger;
use Stewart\Runtime\Worker\WorkerMqtt;
use Stewart\Runtime\Worker\WorkerRegistryEditor;
use Stewart\Store\DisabledStores;
use Stewart\Store\Stores;
use Stewart\Sun\LocatedSunCalendar;
use Stewart\Sun\NoaaSolarCalculator;
use Stewart\Support\State\StewartIdentity;

final class AppRuntimeServicesFixture
{
    public const string STEWART_USER_ID = 'stewart-user';

    public const float LATITUDE = 47.4979;

    private function __construct() {}

    public static function createAppRuntimeServices(
        Transport $transport = new NullTransport(),
        StateCache $stateCache = new StateCache(),
        AppResourcesFixture $resources = new AppResourcesFixture(),
        Stores $stores = new DisabledStores(),
        ?WorkerLogger $logger = null,
        bool $mqttEnabled = false,
        ?SunCalendar $sunCalendar = null,
    ): AppRuntimeServices {
        $timers = $resources->timers;
        $logger ??= new WorkerLogger($transport, new StderrFallback(new WorkerId(0)), ResourceScope::shared(), new LogVerbosityPolicy(LogLevel::Debug));
        $sunCalendar ??= new LocatedSunCalendar(new GeoLocation(self::LATITUDE, 19.0402), $timers->clock, new NoaaSolarCalculator());

        return new AppRuntimeServices(
            logger: $logger,
            clock: $timers->clock,
            timers: $timers,
            scheduler: WorkerSchedulerFixture::createWorkerScheduler($resources->schedules, $sunCalendar, $logger, ResourceScope::shared(), $timers, $stateCache, $resources->dispatcher),
            context: WorkerHaContextFixture::createWorkerHaContext(
                transport: $transport,
                scope: ResourceScope::shared(),
                timers: $timers,
                stateCache: $stateCache,
                dispatcher: $resources->dispatcher,
                callTimeout: Duration::seconds(30),
                identity: new StewartIdentity(self::STEWART_USER_ID),
            ),
            stores: $stores,
            mqtt: new WorkerMqtt(
                $transport,
                new DispatchStreams($resources->dispatcher, $timers, $stateCache, new RegistryCache()),
                new AppActivityCounters(),
                TestBootstrap::createWorkerSettings(mqttEnabled: $mqttEnabled),
                ResourceScope::shared(),
            ),
            exposure: new WorkerEntityExposure(
                new ExposureChannel(
                    new ExposureRequester($transport, new PendingRequests(new CorrelationIdSequence(new WorkerId(0))), $timers, TestBootstrap::createWorkerSettings(callTimeout: Duration::seconds(30))),
                    $resources->exposedHandles,
                    new ExposedCommandStreams($resources->dispatcher, $timers, new ExposedCommandSettlements($transport, new NullLogger())),
                ),
                ResourceScope::shared(),
            ),
            registryEditor: new WorkerRegistryEditor(
                new RegistryEditRequester($transport, new PendingRequests(new CorrelationIdSequence(new WorkerId(0))), new ConnectionStatus(), $timers, TestBootstrap::createWorkerSettings(callTimeout: Duration::seconds(30))),
                ResourceScope::shared(),
            ),
            sunCalendar: $sunCalendar,
        );
    }
}
