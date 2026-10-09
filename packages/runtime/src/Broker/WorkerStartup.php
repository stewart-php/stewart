<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\Exposure\ExposureReconciler;
use Stewart\Runtime\Exception\BrokerException;
use Throwable;

use function Amp\async;
use function Amp\Future\awaitAll;

final readonly class WorkerStartup
{
    public function __construct(
        private WorkerSlotCollection $workerSlots,
        private WorkerPool $pool,
        private WorkerSlotRegistry $slots,
        private WorkerWatchdog $watchdog,
        private BrokerWorkerEvents $workerEvents,
        private ExposureReconciler $reconciler,
        private HaSession $session,
        private BrokerRun $run,
        private LoggerInterface $logger,
    ) {}

    /** @throws Throwable */
    public function startWorkers(): void
    {
        $this->spawnEveryWorker();

        // The run can end while a spawn suspends; run() then reports that reason instead.
        if (!$this->run->isRunning()) {
            return;
        }

        if (!$this->workerSlots->isEmpty() && $this->slots->countLiveWorkers() === 0 && $this->slots->countPendingRestarts() === 0) {
            throw BrokerException::noWorkersStarted($this->workerSlots->count());
        }

        $this->watchdog->startProbing();
        $this->reconciler->scheduleOnceWorkersSettle();

        $this->logger->info('Stewart is running', [
            'workers' => $this->slots->countLiveWorkers(),
            'entities' => $this->session->countEntities(),
        ]);
    }

    /** @throws Throwable */
    private function spawnEveryWorker(): void
    {
        [$failures] = awaitAll($this->workerSlots->mapToList(
            fn(WorkerSlot $slot) => async(fn(): bool => $this->pool->startWorker($slot, $this->workerEvents)),
        ));

        foreach ($failures as $failure) {
            throw $failure;
        }
    }
}
