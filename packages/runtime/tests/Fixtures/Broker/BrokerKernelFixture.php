<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\BrokerRun;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\DisabledControlPlane;
use Stewart\Runtime\Broker\HaSession;
use Stewart\Runtime\Broker\Mqtt\DisabledMqttLink;
use Stewart\Runtime\Broker\Mqtt\MqttLink;
use Stewart\Runtime\Broker\WorkerPool;
use Stewart\Runtime\Broker\WorkerProbeSequence;
use Stewart\Runtime\Broker\WorkerRestartPolicy;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Broker\WorkerWatchdog;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Kernel\BrokerKernel;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Runtime\Tests\Fixtures\Time\RecordingProcessTimeZone;
use Stewart\Runtime\Time\ProcessTimeZone;
use Stewart\Runtime\Time\SystemClock;

final class BrokerKernelFixture
{
    private const array QUIET_YAML = ['control' => ['listen' => 'off'], 'codegen' => ['namespace' => 'Stewart\\Runtime\\Tests\\NothingGenerated']];

    private function __construct() {}

    /** @param array<string, mixed> $yaml */
    public static function boot(
        HaSession $session,
        WorkerPoolFixture $pools,
        WorkerSlotCollection $workerSlots,
        AppIdCollection $knownAppIds,
        LoggerInterface $logger,
        array $yaml = [],
        SyntheticServices $overrides = new SyntheticServices(),
        ?MqttLink $mqttLink = null,
    ): BootedBroker {
        $config = ConfigFixture::createStewartConfig([...self::QUIET_YAML, ...$yaml]);
        $startTime = new DaemonStartTime($pools->clock);
        $mqttLink ??= new DisabledMqttLink($logger);
        $run = new BrokerRun($pools->pool, $pools->watchdog, $session, new DisabledControlPlane(), $mqttLink, $logger, $config->shutdownGrace, $startTime);
        $services = new SyntheticServices()
            ->withService(HaSession::class, $session)
            ->withService(SystemClock::class, $pools->clock)
            ->withService(ProcessTimeZone::class, new RecordingProcessTimeZone())
            ->withService(WorkerPool::class, $pools->pool)
            ->withService(WorkerSlotRegistry::class, $pools->slots)
            ->withService(WorkerWatchdog::class, $pools->watchdog)
            ->withService(WorkerRestartPolicy::class, $pools->restartPolicy)
            ->withService(WorkerProbeSequence::class, $pools->probes)
            ->withService(WorkerSlotCollection::class, $workerSlots)
            ->withService(BrokerRun::class, $run)
            ->withService(MqttLink::class, $mqttLink)
            ->withService(DaemonStartTime::class, $startTime)
            ->withOverrides($overrides);

        $lifecycle = new BrokerKernel('test', '/nonexistent/services.php', new ProjectRoot(sys_get_temp_dir()), $services)->createBroker(
            $config,
            new AppCatalog(self::listEnabledApps($workerSlots), $knownAppIds, AppIdCollection::fromIds([])),
            $logger,
        );

        return new BootedBroker($lifecycle, $run, $startTime);
    }

    private static function listEnabledApps(WorkerSlotCollection $workerSlots): AppDefinitionCollection
    {
        $apps = [];

        foreach ($workerSlots as $slot) {
            array_push($apps, ...$slot->apps->listValues());
        }

        return AppDefinitionCollection::keyedByAppId($apps);
    }
}
