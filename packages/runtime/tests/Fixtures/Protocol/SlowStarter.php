<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Time\Duration;

use function Amp\delay;

#[Automation(id: 'slow-starter')]
final readonly class SlowStarter implements App
{
    public function __construct(
        private HaContext $ha,
        Scheduler $scheduler,
    ) {
        $scheduler->runAfter(Duration::milliseconds(10), function (): void {
            $this->ha->publish('schedule.fired', null);
        });
    }

    public function initialize(): void
    {
        delay(0.05);

        $this->ha->publish('init.done', null);
    }

    public function dispose(): void {}
}
