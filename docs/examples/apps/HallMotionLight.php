<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'hall-motion-light')]
final readonly class HallMotionLight implements App
{
    public function __construct(
        private HaContext $ha,
        private string $motionSensor = 'binary_sensor.hall_motion',
        private string $light = 'light.hall',
        private int $offAfterMinutes = 2,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges($this->motionSensor)
            ->whenChangedTo('on')
            ->subscribe(function (): void {
                $this->ha->callAction('light.turn_on', target: ActionTarget::forEntities($this->light));
            });

        $this->ha->watchStateChanges($this->motionSensor)
            ->whenChangedTo('off', for: Duration::minutes($this->offAfterMinutes))
            ->subscribe(function (): void {
                $this->ha->callAction('light.turn_off', target: ActionTarget::forEntities($this->light));
            });
    }
}
