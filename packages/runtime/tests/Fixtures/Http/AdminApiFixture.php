<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Http;

use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\Admin\RuntimeAdmin;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\AppPauseOverrideCodec;
use Stewart\Runtime\Broker\AppPauseOverrideStore;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\ConnectionTracker;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Http\Admin\AdminApiCodec;
use Stewart\Runtime\Metrics\PrometheusTextEncoder;
use Stewart\Runtime\Metrics\RuntimeMetricsCollector;
use Stewart\Runtime\Metrics\RuntimeMetricsExporter;
use Stewart\Runtime\Metrics\Source\AppMetricSource;
use Stewart\Runtime\Metrics\Source\DaemonMetricSource;
use Stewart\Runtime\Metrics\Source\WorkerMetricSource;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHomeAssistant;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Runtime\Tests\Fixtures\Control\BrokerStateFixture;
use Stewart\Testing\Store\InMemoryStoreBackend;
use Stewart\Testing\Time\VirtualClock;

final readonly class AdminApiFixture
{
    public VirtualClock $clock;

    public AppPauseRegistry $registry;

    public AppPauseService $pauses;

    public AppPauseOverrideStore $overrides;

    public RuntimeAdmin $admin;

    public AdminApiCodec $codec;

    public RuntimeMetricsExporter $metricsExporter;

    private BrokerStateFixture $broker;

    public function __construct(?InMemoryStoreBackend $overrideBackend = null)
    {
        $this->clock = new VirtualClock();
        $apps = AppDefinitionCollection::keyedByAppId([
            new AppDefinition(new AppId('demo'), Demo::class),
            new AppDefinition(new AppId('porch'), Demo::class, startsPaused: true),
        ]);
        $catalog = new AppCatalog($apps, AppIdCollection::fromList([new AppId('demo'), new AppId('porch'), new AppId('retired')]), AppIdCollection::fromList([]));
        $startTime = new DaemonStartTime($this->clock);
        $startTime->recordStart();
        $this->registry = new AppPauseRegistry($apps, $startTime);
        $this->overrides = $this->createOverrideStore($overrideBackend);
        $this->pauses = new AppPauseService(
            $catalog,
            $this->registry,
            $this->overrides,
            new WorkerSlotRegistry(),
            $this->clock,
            new NullLogger(),
        );
        $metrics = new AppMetrics(WorkerSlotCollection::fromList([new WorkerSlot(new WorkerId(0), $apps)]), $this->clock);
        $this->codec = new AdminApiCodec(AdminApiCodec::createAdminApiWireMapper());
        $this->broker = new BrokerStateFixture();
        $this->admin = new RuntimeAdmin(
            $this->pauses,
            new AppStatusBuilder($metrics, $this->registry, FakeHomeAssistant::createUnreachable()->exposures),
            WorkerSlotCollection::fromList([new WorkerSlot(new WorkerId(0), $apps)]),
            $this->broker->pools->pool,
        );
        $this->metricsExporter = $this->createMetricsExporter($startTime, $metrics);
    }

    public function startWorker(): void
    {
        $this->broker->startWorker(0);
    }

    public function stopEverything(): void
    {
        $this->broker->stopEverything();
    }

    private function createOverrideStore(?InMemoryStoreBackend $backend): AppPauseOverrideStore
    {
        $codec = new AppPauseOverrideCodec(AppPauseOverrideCodec::createOverrideWireMapper());

        return $backend === null
            ? new AppPauseOverrideStore($codec, new NullLogger(), ConfigFixture::createPersistenceConfig())
            : new AppPauseOverrideStore($codec, new NullLogger(), ConfigFixture::createPersistenceConfig(['prefix' => 'stewart']), $backend);
    }

    private function createMetricsExporter(DaemonStartTime $startTime, AppMetrics $metrics): RuntimeMetricsExporter
    {
        $appStatuses = new AppStatusBuilder($metrics, $this->registry, FakeHomeAssistant::createUnreachable()->exposures);
        $snapshots = $this->broker->createSnapshotAssembler($startTime, new ConnectionTracker($this->clock), $appStatuses);
        $collector = new RuntimeMetricsCollector([new DaemonMetricSource(), new WorkerMetricSource(), new AppMetricSource()]);

        return new RuntimeMetricsExporter($snapshots, $collector, new PrometheusTextEncoder());
    }
}
