<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'scheduled')]
final readonly class Scheduled implements App
{
    public function __construct(
        private HaContext $ha,
        private Scheduler $scheduler,
    ) {}

    public function initialize(): void
    {
        $this->scheduler->runEvery(Duration::milliseconds(50), function (ScheduledRun $run): void {
            $this->ha->publish('schedule.fired', [
                'task' => $run->task->getId(),
                'scheduled_for' => $run->scheduledFor->toIso8601(),
            ]);
        });

        // A timer hours away must not keep the worker from exiting.
        $this->scheduler->runDailyAt('03:30', static function (): void {});
    }

    public function dispose(): void {}
}
