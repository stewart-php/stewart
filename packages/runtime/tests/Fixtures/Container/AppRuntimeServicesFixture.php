<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\Identity\StewartIdentity;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\GeneratedRoots;
use Stewart\Runtime\Container\AppRuntimeServices;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\Schedule\WorkerScheduler;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Tests\Fixtures\Worker\AppResourcesFixture;
use Stewart\Runtime\Tests\Fixtures\Worker\WorkerHaContextFixture;
use Stewart\Runtime\Worker\AppActivityCounters;
use Stewart\Runtime\Worker\Context\DispatchStreams;
use Stewart\Runtime\Worker\StderrFallback;
use Stewart\Runtime\Worker\WorkerLogger;
use Stewart\Runtime\Worker\WorkerMqtt;
use Stewart\Store\DisabledStores;
use Stewart\Store\Stores;
use Stewart\Sun\LocatedSunCalendar;
use Stewart\Sun\NoaaSolarCalculator;

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
        ?GeneratedRoots $generated = null,
        ?WorkerLogger $logger = null,
        bool $mqttEnabled = false,
        StewartIdentity $identity = new StewartIdentity(self::STEWART_USER_ID),
        ?SunCalendar $sunCalendar = null,
    ): AppRuntimeServices {
        $timers = $resources->timers;
        $logger ??= new WorkerLogger($transport, new StderrFallback(new WorkerId(0)), ResourceScope::shared());
        $sunCalendar ??= new LocatedSunCalendar(new GeoLocation(self::LATITUDE, 19.0402), $timers->clock, new NoaaSolarCalculator());

        return new AppRuntimeServices(
            logger: $logger,
            clock: $timers->clock,
            deadlines: $timers,
            scheduler: new WorkerScheduler($resources->schedules, $sunCalendar, $logger, ResourceScope::shared()),
            context: WorkerHaContextFixture::createWorkerHaContext(
                transport: $transport,
                scope: ResourceScope::shared(),
                timers: $timers,
                stateCache: $stateCache,
                dispatcher: $resources->dispatcher,
                callTimeout: Duration::seconds(30),
            ),
            stores: $stores,
            mqtt: new WorkerMqtt(
                $transport,
                new DispatchStreams($resources->dispatcher, $timers, $stateCache, new RegistryCache()),
                new AppActivityCounters(),
                $mqttEnabled,
                ResourceScope::shared(),
            ),
            identity: $identity,
            sunCalendar: $sunCalendar,
            generated: $generated,
        );
    }
}
