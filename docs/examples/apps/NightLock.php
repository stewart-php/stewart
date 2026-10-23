<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'night-lock')]
final readonly class NightLock implements App
{
    public function __construct(
        private HaContext $ha,
        private Scheduler $scheduler,
        private string $lock = 'lock.front_door',
    ) {}

    public function initialize(): void
    {
        $this->scheduler->runDailyAt('23:00', function (): void {
            $this->ha->callAction('lock.lock', target: ServiceTarget::forEntities($this->lock));

            if ($this->ha->waitForState($this->lock, 'locked', Duration::seconds(30)) === null) {
                $this->ha->callAction('notify.notify', ['message' => 'The front door did not lock.']);
            }
        });
    }
}
