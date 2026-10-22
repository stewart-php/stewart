<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\DiscoveryResult;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\GeneratedManifest;

final readonly class ReloadPlan
{
    /** @param list<string> $settingsNeedingRestart */
    public function __construct(
        public DiscoveryResult $discovered,
        public AppCatalog $catalog,
        public WorkerSlotCollection $workerSlots,
        public array $settingsNeedingRestart,
        public ?GeneratedManifest $generated,
    ) {}
}
