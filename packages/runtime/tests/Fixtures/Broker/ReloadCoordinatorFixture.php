<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stewart\Runtime\App\AppCatalogResolver;
use Stewart\Runtime\App\CatalogWarnings;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\AppPauseOverrideCodec;
use Stewart\Runtime\Broker\AppPauseOverrideStore;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Broker\AppPlacement;
use Stewart\Runtime\Broker\AppTopology;
use Stewart\Runtime\Broker\BrokerRun;
use Stewart\Runtime\Broker\DaemonLaunchOptions;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\Deploy\ExternalCommandRunner;
use Stewart\Runtime\Broker\Exposure\ExposureReconciler;
use Stewart\Runtime\Broker\GeneratedCodeDrift;
use Stewart\Runtime\Broker\Mqtt\DisabledMqttLink;
use Stewart\Runtime\Broker\Reload\ConfigChangeClassifier;
use Stewart\Runtime\Broker\Reload\ReloadApplier;
use Stewart\Runtime\Broker\Reload\ReloadCheck;
use Stewart\Runtime\Broker\Reload\ReloadCheckReportCodec;
use Stewart\Runtime\Broker\Reload\ReloadCoordinator;
use Stewart\Runtime\Broker\Reload\ReloadPlanner;
use Stewart\Runtime\Broker\Reload\ReloadState;
use Stewart\Runtime\Broker\WorkerPoolListener;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Logging\LogVerbosityPolicy;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigLoaderFixture;
use Stewart\Runtime\Tests\Fixtures\Deploy\ScriptedCommandRunner;
use Stewart\Testing\Time\ManualTimers;

final readonly class ReloadCoordinatorFixture
{
    public const string CONSOLE_SCRIPT = '/project/vendor/bin/stewart';

    public const array HOME_ASSISTANT = [
        'STEWART_HOME_ASSISTANT__URL' => 'ws://ha.invalid:8123/api/websocket',
        'STEWART_HOME_ASSISTANT__TOKEN' => 'reload-token',
    ];

    private const int AUTO_POOL_SIZE = 2;

    private function __construct(
        public ReloadCoordinator $coordinator,
        public AppTopology $topology,
        public AppPauseRegistry $pauses,
        public AppMetrics $metrics,
        public FakeHomeAssistant $homeAssistant,
        public LogVerbosityPolicy $verbosity,
        public GeneratedCodeDrift $drift,
    ) {}

    /** @param array<string, string> $environment */
    public static function createReloadCoordinator(
        WorkerPoolFixture $pools,
        ?BrokerRun $run = null,
        ExternalCommandRunner $commands = new ScriptedCommandRunner(),
        ReloadState $state = new ReloadState(),
        LoggerInterface $logger = new NullLogger(),
        DaemonLaunchOptions $launch = new DaemonLaunchOptions(),
        ?AppTopology $topology = null,
        WorkerPoolListener $listener = new RecordingPoolListener(),
        array $environment = self::HOME_ASSISTANT,
        ?FakeHaSession $session = null,
    ): self {
        $topology ??= AppTopologyFixture::createTopology();
        $session ??= FakeHaSession::createOpened();
        $configs = ConfigLoaderFixture::createLoader(new EnvironmentVariables($environment));
        $homeAssistant = FakeHomeAssistant::createUnreachable();
        $pauses = new AppPauseRegistry($topology->getCatalog()->enabled, new DaemonStartTime($pools->clock));
        $metrics = new AppMetrics($topology->getWorkerSlots(), $pools->clock);
        $verbosity = new LogVerbosityPolicy(LogLevel::Info, $topology->getCatalog()->enabled->listVerbositiesByAppId());
        $drift = new GeneratedCodeDrift($session, $pools->clock, $logger);

        return new self(
            new ReloadCoordinator(
                new ReloadCheck($commands, new ReloadCheckReportCodec(ReloadCheckReportCodec::createReportWireMapper()), $launch, self::CONSOLE_SCRIPT),
                self::createPlanner($configs, $launch),
                new ReloadApplier(
                    $topology,
                    new AppPauseService($topology, $pauses, self::createOverrideStore(), $pools->slots, $pools->clock, $logger),
                    $metrics,
                    $homeAssistant->exposures,
                    new ExposureReconciler($topology, $homeAssistant->exposures, $homeAssistant->client, $homeAssistant->componentTracker, ConfigFixture::createStewartConfig()->expose, new ManualTimers(), $logger),
                    $pools->pool,
                    $listener,
                    new CatalogWarnings(),
                    $configs,
                    $launch,
                    $verbosity,
                    $drift,
                    $logger,
                ),
                $state,
                $run ?? self::createRunningBroker($pools),
                $pools->clock,
                $logger,
            ),
            $topology,
            $pauses,
            $metrics,
            $homeAssistant,
            $verbosity,
            $drift,
        );
    }

    private static function createPlanner(ConfigLoader $configs, DaemonLaunchOptions $launch): ReloadPlanner
    {
        return new ReloadPlanner($configs, new AppCatalogResolver(), new AppPlacement(self::AUTO_POOL_SIZE), new ConfigChangeClassifier(), $launch, $configs->loadConfig($launch->configFile, $launch->listConfigOverrides()));
    }

    private static function createOverrideStore(): AppPauseOverrideStore
    {
        return new AppPauseOverrideStore(new AppPauseOverrideCodec(AppPauseOverrideCodec::createOverrideWireMapper()), new NullLogger(), ConfigFixture::createPersistenceConfig());
    }

    private static function createRunningBroker(WorkerPoolFixture $pools): BrokerRun
    {
        $logger = new NullLogger();
        $run = new BrokerRun($pools->pool, $pools->watchdog, FakeHaSession::createOpened(), new DisabledMqttLink($logger), $logger, ConfigFixture::createStewartConfig(), new DaemonStartTime($pools->clock));
        $run->start();

        return $run;
    }
}
