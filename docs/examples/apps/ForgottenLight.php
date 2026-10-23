<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'forgotten-light')]
final readonly class ForgottenLight implements App
{
    public function __construct(
        private HaContext $ha,
        private Scheduler $scheduler,
        private string $motionSensor = 'binary_sensor.hall_motion',
        private string $light = 'light.hall',
    ) {}

    public function initialize(): void
    {
        $this->scheduler->runEvery(Duration::minutes(15), function (): void {
            if ($this->ha->getState($this->light)?->state !== 'on') {
                return;
            }

            $motion = $this->ha->getHistory($this->motionSensor, HistoryQuery::lastFor(Duration::minutes(30)));

            if (!$motion->hasBeenIn('on')) {
                $this->ha->callAction('light.turn_off', target: ServiceTarget::forEntities($this->light));
            }
        });
    }
}
