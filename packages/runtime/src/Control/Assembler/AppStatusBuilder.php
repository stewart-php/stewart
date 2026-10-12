<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Assembler;

use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\AppRunningTotals;
use Stewart\Runtime\Broker\Exposure\ExposureLink;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;

final readonly class AppStatusBuilder
{
    public function __construct(
        private AppMetrics $metrics,
        private AppPauseRegistry $pausedApps,
        private ExposureLink $exposures,
    ) {}

    /** @return list<AppStatus> */
    public function buildAppStatuses(): array
    {
        return array_map($this->buildAppStatus(...), $this->metrics->listRunningTotals());
    }

    /** @return list<AppStatus> */
    public function buildHostedAppStatuses(): array
    {
        $appTotals = array_filter($this->metrics->listRunningTotals(), static fn(AppRunningTotals $totals): bool => !$totals->isSharedScope());

        return array_values(array_map($this->buildAppStatus(...), $appTotals));
    }

    public function findAppStatus(AppId $appId): ?AppStatus
    {
        return $this->metrics->findAppRunningTotals($appId)?->buildAppStatus($this->pausedApps, $this->exposures);
    }

    private function buildAppStatus(AppRunningTotals $totals): AppStatus
    {
        return $totals->buildAppStatus($this->pausedApps, $this->exposures);
    }
}
