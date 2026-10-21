<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Control\Protocol\Status\FailureReport;
use Stewart\Runtime\Ipc\Message\AppActivityReport;
use Stewart\Runtime\Ipc\Message\AppFailed;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Model\ExposedCommandOutcome;
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
                $this->runningTotals[$app->id->value] = AppRunningTotals::forApp($app->id, $app->class, $slot->workerId);
            }
        }
    }

    // Totals of an app that stays on its worker keep counting; a moved app starts again under its new worker.
    public function replaceAppTotals(WorkerSlotCollection $workerSlots): void
    {
        $kept = [];

        foreach ($workerSlots as $slot) {
            foreach ($slot->apps as $app) {
                $existing = $this->runningTotals[$app->id->value] ?? null;
                $kept[$app->id->value] = $existing !== null && $existing->isPlacedOn($slot->workerId, $app->class)
                    ? $existing
                    : AppRunningTotals::forApp($app->id, $app->class, $slot->workerId);
            }

            $sharedKey = AppRunningTotals::buildMetricsKey(ResourceScope::shared(), $slot->workerId);

            if (isset($this->runningTotals[$sharedKey])) {
                $kept[$sharedKey] = $this->runningTotals[$sharedKey];
            }
        }

        $this->runningTotals = $kept;
    }

    public function recordActivityReports(WorkerHandle $handle, Pong $pong): void
    {
        // A respawned worker counts from zero, so its first report is all new activity.
        $previous = $this->lastActivityReports[$handle] ?? [];
        $latest = [];
        $at = $this->clock->getNow();

        foreach ($pong->apps as $report) {
            $scope = $report->scope->wireValue();
            $this->findOrCreateRunningTotals($handle->id, $report->scope)?->recordActivityReport($report, $previous[$scope] ?? null, $at);
            $latest[$scope] = $report;
        }

        $this->lastActivityReports[$handle] = $latest;
    }

    public function recordFinishedCall(WorkerId $workerId, ResourceScope $scope, ServiceCallOutcome $outcome, Duration $latency): void
    {
        $this->findOrCreateRunningTotals($workerId, $scope)?->recordCall($outcome, $latency);
    }

    public function recordRefusedCall(WorkerId $workerId, ResourceScope $scope): void
    {
        $this->findOrCreateRunningTotals($workerId, $scope)?->recordCall(ServiceCallOutcome::Refused, null);
    }

    public function recordExposedCommand(AppId $appId, ExposedCommandOutcome $outcome): void
    {
        $this->findAppRunningTotals($appId)?->recordExposedCommand($outcome);
    }

    public function recordLastFailure(WorkerId $workerId, AppFailed $failure): void
    {
        $this->findOrCreateRunningTotals($workerId, $failure->scope)?->recordLastFailure(
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

    /** @return list<AppRunningTotals> */
    public function listRunningTotals(): array
    {
        return array_values($this->runningTotals);
    }

    public function findAppRunningTotals(AppId $appId): ?AppRunningTotals
    {
        return $this->runningTotals[$appId->value] ?? null;
    }

    // An app a reload removed can still report from its retiring worker; its totals are gone for good.
    private function findOrCreateRunningTotals(WorkerId $workerId, ResourceScope $scope): ?AppRunningTotals
    {
        $key = AppRunningTotals::buildMetricsKey($scope, $workerId);

        if (!$scope->isShared()) {
            return $this->runningTotals[$key] ?? null;
        }

        return $this->runningTotals[$key] ??= AppRunningTotals::forScope($scope, $workerId);
    }
}
