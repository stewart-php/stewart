<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Http;

use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\AppPauseOverrideCodec;
use Stewart\Runtime\Broker\AppPauseOverrideStore;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Http\Admin\AdminApiCodec;
use Stewart\Runtime\Http\Admin\AppsAdminApi;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Testing\Time\VirtualClock;

final readonly class AdminApiFixture
{
    public VirtualClock $clock;

    public AppPauseRegistry $registry;

    public AppPauseService $pauses;

    public AppsAdminApi $api;

    public AdminApiCodec $codec;

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
        $this->api = new AppsAdminApi(new AppStatusBuilder($metrics, $this->registry), $this->pauses, $catalog);
        $this->codec = new AdminApiCodec(AdminApiCodec::createAdminApiWireMapper());
    }
}
