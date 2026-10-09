<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Http;

use Psr\Log\NullLogger;
use Stewart\Client\Component\ComponentInstance;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\AppPauseOutcomeMessages;
use Stewart\Runtime\Broker\AppPauseOverrideCodec;
use Stewart\Runtime\Broker\AppPauseOverrideStore;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\Component\ComponentTracker;
use Stewart\Runtime\Broker\ConnectionTracker;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\Deploy\DeployState;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Control\Assembler\BrokerStatsBuilder;
use Stewart\Runtime\Control\Assembler\ComponentStatusBuilder;
use Stewart\Runtime\Control\Assembler\DaemonInfoBuilder;
use Stewart\Runtime\Control\Assembler\DeployStatusBuilder;
use Stewart\Runtime\Control\Assembler\RegistrationInfoBuilder;
use Stewart\Runtime\Control\Assembler\StoreHealthBuilder;
use Stewart\Runtime\Control\Assembler\WorkerStatusBuilder;
use Stewart\Runtime\Control\SnapshotAssembler;
use Stewart\Runtime\Http\Admin\AdminApiCodec;
use Stewart\Runtime\Http\Admin\AppsAdminApi;
use Stewart\Runtime\Metrics\PrometheusTextEncoder;
use Stewart\Runtime\Metrics\RuntimeMetricsCollector;
use Stewart\Runtime\Metrics\RuntimeMetricsExporter;
use Stewart\Runtime\Metrics\Source\AppMetricSource;
use Stewart\Runtime\Metrics\Source\DaemonMetricSource;
use Stewart\Runtime\Metrics\Source\WorkerMetricSource;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\ExposureLinkFixture;
use Stewart\Runtime\Tests\Fixtures\Control\BrokerStateFixture;
use Stewart\Testing\Time\VirtualClock;

final readonly class AdminApiFixture
{
    public VirtualClock $clock;

    public AppPauseRegistry $registry;

    public AppPauseService $pauses;

    public AppsAdminApi $api;

    public AdminApiCodec $codec;

    public RuntimeMetricsExporter $metricsExporter;

    private BrokerStateFixture $broker;

    public function __construct()
    {
        $this->clock = new VirtualClock();
        $apps = AppDefinitionCollection::keyedByAppId([
            new AppDefinition(new AppId('demo'), Demo::class),
            new AppDefinition(new AppId('porch'), Demo::class, startsPaused: true),
        ]);
        $catalog = new AppCatalog($apps, AppIdCollection::fromIds([new AppId('demo'), new AppId('porch'), new AppId('retired')]), AppIdCollection::fromIds([]));
        $startTime = new DaemonStartTime($this->clock);
        $startTime->recordStart();
        $this->registry = new AppPauseRegistry($apps, $startTime);
        $this->pauses = new AppPauseService(
            $catalog,
            $this->registry,
            new AppPauseOverrideStore(new AppPauseOverrideCodec(AppPauseOverrideCodec::createOverrideWireMapper()), new NullLogger()),
            new WorkerSlotRegistry(),
            $this->clock,
            new NullLogger(),
        );
        $metrics = new AppMetrics(WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), $apps)]), $this->clock);
        $this->api = new AppsAdminApi(new AppStatusBuilder($metrics, $this->registry, ExposureLinkFixture::createWithoutExposures()), $this->pauses, new AppPauseOutcomeMessages());
        $this->codec = new AdminApiCodec(AdminApiCodec::createAdminApiWireMapper());
        $this->broker = new BrokerStateFixture();
        $this->metricsExporter = $this->createMetricsExporter($startTime, $metrics);
    }

    public function stopEverything(): void
    {
        $this->broker->stopEverything();
    }

    private function createMetricsExporter(DaemonStartTime $startTime, AppMetrics $metrics): RuntimeMetricsExporter
    {
        $broker = $this->broker;
        $snapshots = new SnapshotAssembler(
            daemonInfo: new DaemonInfoBuilder($broker->session, '0.1.0-test', $startTime),
            brokerStats: new BrokerStatsBuilder($broker->pools->slots, $broker->callSlots, $broker->registry),
            workerStatuses: new WorkerStatusBuilder($broker->pools->slots, $broker->pools->watchdog, $broker->pools->restartPolicy, $broker->callSlots),
            registrationInfos: new RegistrationInfoBuilder($broker->registry),
            storeHealth: new StoreHealthBuilder($broker->pools->slots, storeConfigured: false),
            appStatuses: new AppStatusBuilder($metrics, $this->registry, ExposureLinkFixture::createWithoutExposures()),
            deployStatus: new DeployStatusBuilder(new DeployState()),
            componentStatus: new ComponentStatusBuilder(new ComponentTracker($this->clock), new ExposeConfig(ComponentInstance::parse('default'), Duration::seconds(10))),
            connection: new ConnectionTracker($this->clock),
            clock: $this->clock,
        );
        $collector = new RuntimeMetricsCollector([new DaemonMetricSource(), new WorkerMetricSource(), new AppMetricSource()]);

        return new RuntimeMetricsExporter($snapshots, $collector, new PrometheusTextEncoder());
    }
}
