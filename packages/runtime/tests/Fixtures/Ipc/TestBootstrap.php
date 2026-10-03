<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Ipc;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
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
    ): Bootstrap {
        return new Bootstrap(
            protocol: IpcCodec::PROTOCOL_VERSION,
            workerId: new WorkerId(0),
            timeZone: $timeZone,
            apps: WorkerAppsFragment::fromCollection(WorkerAppCollection::fromApps($apps)),
            settings: self::createSettings($callTimeout ?? Duration::seconds(30), $initializeTimeout, $servicesFile),
            store: $store,
            knownAppIds: AppIdsFragment::fromCollection(AppIdCollection::fromIds(array_map(static fn(WorkerApp $app): AppId => $app->id, $apps))),
        );
    }

    private static function createSettings(Duration $callTimeout, ?Duration $initializeTimeout, ?string $servicesFile): WorkerSettings
    {
        return new WorkerSettings(
            logLevel: LogLevel::Debug,
            callTimeout: $callTimeout,
            shutdownGrace: Duration::seconds(5),
            subscriptionBuffer: 100,
            initializeTimeout: $initializeTimeout,
            servicesFile: $servicesFile,
            generatedNamespace: 'Stewart\\Generated',
        );
    }
}
