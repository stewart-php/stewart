<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;

final class AppTopology
{
    public function __construct(
        private AppCatalog $catalog,
        private WorkerSlotCollection $workerSlots,
    ) {}

    public function getCatalog(): AppCatalog
    {
        return $this->catalog;
    }

    public function getWorkerSlots(): WorkerSlotCollection
    {
        return $this->workerSlots;
    }

    public function replaceTopology(AppCatalog $catalog, WorkerSlotCollection $workerSlots): void
    {
        $this->catalog = $catalog;
        $this->workerSlots = $workerSlots;
    }
}
