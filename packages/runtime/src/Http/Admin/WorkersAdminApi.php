<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http\Admin;

use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\WorkerPool;
use Stewart\Runtime\Broker\WorkerRestartOutcome;
use Stewart\Runtime\Exception\WorkerException;
use Stewart\Runtime\Http\Admin\Response\AdminCommandResult;

final readonly class WorkersAdminApi
{
    public function __construct(
        private WorkerSlotCollection $workerSlots,
        private WorkerPool $pool,
    ) {}

    /** @throws WorkerException */
    public function restartWorker(string $workerId): AdminCommandResult
    {
        $slot = $this->workerSlots->requireSlotById($workerId);
        $outcome = $this->pool->restartWorker($slot);

        return new AdminCommandResult($outcome === WorkerRestartOutcome::Begun, $outcome->describeForWorker($slot->workerId), null);
    }
}
