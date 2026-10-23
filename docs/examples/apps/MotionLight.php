<?php

declare(strict_types=1);

namespace Automations;

use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\App;
use Stewart\Contracts\AppTemplate;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Time\Duration;

#[AppTemplate]
final readonly class MotionLight implements App
{
    public function __construct(
        private HaContext $ha,
        private string $motionSensor,
        private string $light,
        private int $offAfterMinutes = 3,
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
