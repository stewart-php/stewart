<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'night-motion-light')]
final readonly class NightMotionLight implements App
{
    public function __construct(
        private HaContext $ha,
        private SunCalendar $sun,
        private Clock $clock,
        private string $motionSensor = 'binary_sensor.hall_motion',
        private string $light = 'light.hall',
        private int $offAfterMinutes = 3,
        private int $dayBrightness = 100,
        private int $nightBrightness = 10,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges($this->motionSensor)
            ->whenChangedTo('on')
            ->filter(fn(): bool => !$this->sun->isSunUp())
            ->subscribe(function (): void {
                $brightness = $this->clock->isWithin('23:00', '06:00') ? $this->nightBrightness : $this->dayBrightness;
                $this->ha->callService('light', 'turn_on', ['brightness_pct' => $brightness], ServiceTarget::forEntities($this->light));
            });

        $this->ha->watchStateChanges($this->motionSensor)
            ->whenChangedTo('off', for: Duration::minutes($this->offAfterMinutes))
            ->subscribe(function (): void {
                if ($this->ha->getState($this->light)?->state === 'on') {
                    $this->ha->callService('light', 'turn_off', target: ServiceTarget::forEntities($this->light));
                }
            });
    }

    public function dispose(): void {}
}
