<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Exposure;

use Psr\Log\LoggerInterface;
use Stewart\Client\Component\ExposedEntityReconcile;
use Stewart\Client\Exception\HaClientException;
use Stewart\Client\HaClient;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Timers;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\Component\ComponentTracker;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Model\WorkerId;

// Once per run, removes this daemon's entities that no app exposed, keeping every entity of apps that are not running.
final class ExposureReconciler
{
    private const int GRACE_SECONDS_AFTER_WORKERS_SETTLE = 30;

    /** @var array<int, AppIdCollection> */
    private array $runningAppsByWorker = [];

    private bool $graceStarted = false;

    private bool $due = false;

    public function __construct(
        private readonly WorkerSlotCollection $workerSlots,
        private readonly ExposureLink $exposures,
        private readonly HaClient $client,
        private readonly ComponentTracker $tracker,
        private readonly ExposeConfig $expose,
        private readonly Timers $timers,
        private readonly LoggerInterface $logger,
    ) {}

    public function recordWorkerReady(WorkerId $workerId, AppIdCollection $runningApps): void
    {
        $this->runningAppsByWorker[$workerId->value] = $runningApps;
        $this->scheduleOnceWorkersSettle();
    }

    public function recordWorkerQuarantined(WorkerId $workerId): void
    {
        $this->runningAppsByWorker[$workerId->value] = AppIdCollection::empty();
        $this->scheduleOnceWorkersSettle();
    }

    public function scheduleOnceWorkersSettle(): void
    {
        if (!$this->expose->prune || $this->graceStarted || !$this->haveAllWorkersSettled()) {
            return;
        }

        $this->graceStarted = true;
        $this->timers->startTimer(Duration::seconds(self::GRACE_SECONDS_AFTER_WORKERS_SETTLE), function (): void {
            $this->due = true;
            $this->reconcileIfDue();
        });
    }

    public function reconcileIfDue(): void
    {
        if (!$this->due || !$this->tracker->isActive()) {
            return;
        }

        $this->due = false;

        try {
            $removed = $this->client->reconcileExposedEntities($this->buildReconcile());
        } catch (HaClientException $e) {
            $this->due = true;
            $this->logger->debug('Pruning exposed entities waits for the next stewart integration session', ['exception' => $e]);

            return;
        }

        if (!$removed->isEmpty()) {
            $this->logger->info('Removed exposed entities that no app exposes any more', ['entities' => $removed->toStrings()]);
        }
    }

    private function haveAllWorkersSettled(): bool
    {
        return !$this->workerSlots->containsWhere(fn(WorkerSlot $slot): bool => !isset($this->runningAppsByWorker[$slot->workerId->value]));
    }

    private function buildReconcile(): ExposedEntityReconcile
    {
        $keptApps = [];

        foreach ($this->workerSlots as $slot) {
            $runningApps = $this->runningAppsByWorker[$slot->workerId->value] ?? AppIdCollection::empty();

            foreach ($slot->listAppIds() as $appId) {
                if (!$runningApps->containsId($appId)) {
                    $keptApps[] = $appId;
                }
            }
        }

        return new ExposedEntityReconcile($this->expose->instance, $this->exposures->listKeptAddresses(), AppIdCollection::fromIds($keptApps));
    }
}
