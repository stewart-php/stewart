<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Assembler;

use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\AppRunningTotals;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Control\Protocol\Status\Collection\AppStatusCollection;

final readonly class AppStatusBuilder
{
    public function __construct(private AppMetrics $metrics) {}

    public function buildAppStatuses(): AppStatusCollection
    {
        return AppStatusCollection::fromStatuses($this->metrics->listRunningTotals()->mapToList(static fn(AppRunningTotals $totals): AppStatus => $totals->buildAppStatus()));
    }
}
