<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Assembler;

use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Control\Protocol\Status\ScheduleStatus;

final readonly class ScheduleStatusBuilder
{
    public function __construct(private WorkerSlotRegistry $slots) {}

    /** @return list<ScheduleStatus> */
    public function buildScheduleStatuses(): array
    {
        $statuses = [];

        foreach ($this->slots->listLiveHandles() as $handle) {
            foreach ($handle->lastPong->apps ?? [] as $app) {
                foreach ($app->scheduleReports as $report) {
                    $statuses[] = new ScheduleStatus(
                        appId: $app->scope->wireValue(),
                        workerId: $handle->id->value,
                        taskId: $report->taskId,
                        description: $report->description,
                        nextRunAt: $report->nextRunAt,
                        lastRunAt: $report->lastRunAt,
                    );
                }
            }
        }

        usort($statuses, static fn(ScheduleStatus $a, ScheduleStatus $b): int => $a->workerId <=> $b->workerId);

        return $statuses;
    }
}
