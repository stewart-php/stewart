<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Ipc;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Ipc\Collection\WorkerAppCollection;
use Stewart\Runtime\Ipc\Message\Bootstrap;
use Stewart\Runtime\Ipc\StoreSettings;
use Stewart\Runtime\Ipc\Wire\AppIdsFragment;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Ipc\Wire\WorkerAppsFragment;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Ipc\WorkerSettings;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\WorkerId;

final class TestBootstrap
{
    private function __construct() {}

    /** @param list<WorkerApp> $apps */
    public static function createForApps(
        array $apps,
        ?Duration $callTimeout = null,
        ?Duration $initializeTimeout = null,
        string $timeZone = 'UTC',
        ?StoreSettings $store = null,
        ?string $servicesFile = null,
        bool $mqttEnabled = false,
        ?string $haUserId = null,
        ?GeoLocation $location = null,
        ?AppIdCollection $pausedAppIds = null,
    ): Bootstrap {
        return new Bootstrap(
            protocol: IpcCodec::PROTOCOL_VERSION,
            workerId: new WorkerId(0),
            timeZone: $timeZone,
            location: $location,
            apps: WorkerAppsFragment::fromCollection(WorkerAppCollection::fromList($apps)),
            settings: self::createWorkerSettings(callTimeout: $callTimeout ?? Duration::seconds(30), initializeTimeout: $initializeTimeout, servicesFile: $servicesFile, mqttEnabled: $mqttEnabled),
            store: $store,
            knownAppIds: AppIdsFragment::fromCollection(AppIdCollection::fromList(array_map(static fn(WorkerApp $app): AppId => $app->id, $apps))),
            pausedAppIds: AppIdsFragment::fromCollection($pausedAppIds ?? AppIdCollection::empty()),
            haUserId: $haUserId,
        );
    }

    public static function createWorkerSettings(
        LogLevel $logLevel = LogLevel::Debug,
        ?Duration $callTimeout = null,
        ?Duration $shutdownGrace = null,
        int $subscriptionBuffer = 100,
        ?Duration $initializeTimeout = null,
        ?string $servicesFile = null,
        bool $mqttEnabled = false,
    ): WorkerSettings {
        return new WorkerSettings(
            logLevel: $logLevel,
            callTimeout: $callTimeout ?? Duration::seconds(30),
            shutdownGrace: $shutdownGrace ?? Duration::seconds(5),
            subscriptionBuffer: $subscriptionBuffer,
            initializeTimeout: $initializeTimeout,
            servicesFile: $servicesFile,
            generatedNamespace: 'Stewart\\Generated',
            mqttEnabled: $mqttEnabled,
        );
    }
}
