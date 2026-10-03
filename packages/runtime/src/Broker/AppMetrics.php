<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\Collection\AppRunningTotalsCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Control\Protocol\Status\FailureReport;
use Stewart\Runtime\Ipc\Message\AppActivityReport;
use Stewart\Runtime\Ipc\Message\AppFailed;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Stewart\Runtime\Model\WorkerId;
use WeakMap;

final class AppMetrics
{
    /** @var array<string, AppRunningTotals> */
    private array $runningTotals = [];

    /** @var WeakMap<WorkerHandle, array<string, AppActivityReport>> */
    private WeakMap $lastActivityReports;

    public function __construct(
        WorkerSlotCollection $workerSlots,
        private readonly Clock $clock,
    ) {
        $this->lastActivityReports = new WeakMap();

        foreach ($workerSlots as $slot) {
            foreach ($slot->apps as $app) {
                $this->runningTotals[$app->id->value] = AppRunningTotals::forApp($app->id->value, $app->class, $slot->workerId);
            }
        }
    }

    public function recordActivityReports(WorkerHandle $handle, Pong $pong): void
    {
        // A respawned worker counts from zero, so its first report is all new activity.
        $previous = $this->lastActivityReports[$handle] ?? [];
        $latest = [];
        $at = $this->clock->getNow();

        foreach ($pong->apps as $report) {
            $scope = $report->scope->wireValue();
            $this->findOrCreateRunningTotals($handle->id, $report->scope)->recordActivityReport($report, $previous[$scope] ?? null, $at);
            $latest[$scope] = $report;
        }

        $this->lastActivityReports[$handle] = $latest;
    }

    public function recordFinishedCall(WorkerId $workerId, ResourceScope $scope, ServiceCallOutcome $outcome, Duration $latency): void
    {
        $this->findOrCreateRunningTotals($workerId, $scope)->recordCall($outcome, $latency);
    }

    public function recordRefusedCall(WorkerId $workerId, ResourceScope $scope): void
    {
        $this->findOrCreateRunningTotals($workerId, $scope)->recordCall(ServiceCallOutcome::Refused, null);
    }

    public function recordLastFailure(WorkerId $workerId, AppFailed $failure): void
    {
        $this->findOrCreateRunningTotals($workerId, $failure->scope)->recordLastFailure(
            new FailureReport(
                $failure->phase,
                $failure->class,
                $failure->message,
                $failure->origin,
                $failure->details?->reason,
                $this->clock->getNow(),
            ),
        );
    }

    public function listRunningTotals(): AppRunningTotalsCollection
    {
        return AppRunningTotalsCollection::fromTotals($this->runningTotals);
    }

    private function findOrCreateRunningTotals(WorkerId $workerId, ResourceScope $scope): AppRunningTotals
    {
        return $this->runningTotals[AppRunningTotals::buildMetricsKey($scope, $workerId)] ??= AppRunningTotals::forScope($scope, $workerId);
    }
}
