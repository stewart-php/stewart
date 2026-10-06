<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Control\Protocol\Status\AppCounters;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Control\Protocol\Status\FailureReport;
use Stewart\Runtime\Control\Protocol\Status\ServiceCallStats;
use Stewart\Runtime\Ipc\Message\AppActivityReport;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Stewart\Runtime\Model\WorkerId;

final class AppRunningTotals
{
    private ?AppState $state = null;

    private ?Instant $reportedAt = null;

    private int $subscriptions = 0;

    private int $schedules = 0;

    private int $delivered = 0;

    private int $subscriptionDropped = 0;

    private int $scheduleRuns = 0;

    private int $publishes = 0;

    private int $failures = 0;

    private int $suppressed = 0;

    private ?FailureReport $lastFailure = null;

    /** @var array<string, ServiceCallStatsRecorder> */
    private array $calls = [];

    private function __construct(
        private readonly string $id,
        private readonly ?AppId $appId,
        private readonly string $class,
        private readonly ?WorkerId $workerId,
    ) {}

    public static function forApp(AppId $appId, string $class, ?WorkerId $workerId): self
    {
        return new self($appId->value, $appId, $class, $workerId);
    }

    public static function forScope(ResourceScope $scope, WorkerId $workerId): self
    {
        return new self($scope->wireValue(), $scope->appId, '', $workerId);
    }

    // The shared services.php scope exists once per worker, so its totals are kept per worker.
    public static function buildMetricsKey(ResourceScope $scope, WorkerId $workerId): string
    {
        return $scope->isShared() ? $scope->wireValue() . '#' . $workerId->value : $scope->wireValue();
    }

    public function recordActivityReport(AppActivityReport $report, ?AppActivityReport $previous, Instant $at): void
    {
        $this->state = $report->state;
        $this->reportedAt = $at;
        $this->subscriptions = $report->subscriptions;
        $this->schedules = $report->schedules;
        $this->delivered += max(0, $report->delivered - ($previous->delivered ?? 0));
        $this->subscriptionDropped += max(0, $report->subscriptionDropped - ($previous->subscriptionDropped ?? 0));
        $this->scheduleRuns += max(0, $report->scheduleRuns - ($previous->scheduleRuns ?? 0));
        $this->publishes += max(0, $report->publishes - ($previous->publishes ?? 0));
        $this->failures += max(0, $report->failures - ($previous->failures ?? 0));
        $this->suppressed += max(0, $report->suppressed - ($previous->suppressed ?? 0));
    }

    public function recordCall(ServiceCallOutcome $outcome, ?Duration $latency): void
    {
        ($this->calls[$outcome->value] ??= new ServiceCallStatsRecorder($outcome))->recordCall($latency);
    }

    public function recordLastFailure(FailureReport $failure): void
    {
        $this->lastFailure = $failure;
    }

    public function buildAppStatus(AppPauseRegistry $pausedApps): AppStatus
    {
        $calls = $this->calls;
        ksort($calls);

        return new AppStatus(
            id: $this->id,
            class: $this->class,
            workerId: $this->workerId?->value,
            state: $this->state,
            paused: $this->appId !== null && $pausedApps->isPaused($this->appId),
            reportedAt: $this->reportedAt,
            subscriptions: $this->subscriptions,
            schedules: $this->schedules,
            counters: new AppCounters(
                delivered: $this->delivered,
                subscriptionDropped: $this->subscriptionDropped,
                scheduleRuns: $this->scheduleRuns,
                publishes: $this->publishes,
                failures: $this->failures,
                suppressed: $this->suppressed,
            ),
            serviceCalls: array_values(array_map(static fn(ServiceCallStatsRecorder $recorder): ServiceCallStats => $recorder->buildServiceCallStats(), $calls)),
            lastFailure: $this->lastFailure,
        );
    }
}
