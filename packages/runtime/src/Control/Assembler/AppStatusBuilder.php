<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Assembler;

use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\AppRunningTotals;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Control\Protocol\Status\Collection\AppStatusCollection;

final readonly class AppStatusBuilder
{
    public function __construct(
        private AppMetrics $metrics,
        private AppPauseRegistry $pausedApps,
    ) {}

    public function buildAppStatuses(): AppStatusCollection
    {
        return AppStatusCollection::fromStatuses($this->metrics->listRunningTotals()->mapToList(fn(AppRunningTotals $totals): AppStatus => $totals->buildAppStatus($this->pausedApps)));
    }

    public function buildHostedAppStatuses(): AppStatusCollection
    {
        $appTotals = $this->metrics->listRunningTotals()->filter(static fn(AppRunningTotals $totals): bool => !$totals->isSharedScope());

        return AppStatusCollection::fromStatuses($appTotals->mapToList(fn(AppRunningTotals $totals): AppStatus => $totals->buildAppStatus($this->pausedApps)));
    }

    public function findAppStatus(AppId $appId): ?AppStatus
    {
        return $this->metrics->findAppRunningTotals($appId)?->buildAppStatus($this->pausedApps);
    }
}
