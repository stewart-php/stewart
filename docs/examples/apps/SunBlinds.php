<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'sun-blinds')]
final readonly class SunBlinds implements App
{
    public function __construct(
        private HaContext $ha,
        private Scheduler $scheduler,
        private SunCalendar $sun,
        private string $blinds = 'cover.south_blinds',
        private string $outsideTemperature = 'sensor.outside_temperature',
        private float $closeAbove = 24.0,
        private float $facadeFrom = 135.0,
        private float $facadeTo = 225.0,
    ) {}

    public function initialize(): void
    {
        $this->scheduler->runEvery(Duration::minutes(10), function (): void {
            if ($this->isSunOnTheFacade() && $this->isWarmOutside() && $this->areBlindsIn('open')) {
                $this->moveBlinds('cover.close_cover');
            }
        });

        $this->scheduler->runAtSunEvent(SunEvent::Sunset, function (): void {
            if ($this->areBlindsIn('closed')) {
                $this->moveBlinds('cover.open_cover');
            }
        });
    }

    private function isSunOnTheFacade(): bool
    {
        $position = $this->sun->getCurrentPosition();

        return $position->isWithinAzimuth($this->facadeFrom, $this->facadeTo) && $position->isHigherThan(10.0);
    }

    private function isWarmOutside(): bool
    {
        return ($this->ha->getState($this->outsideTemperature)?->getStateAsFloat() ?? 0.0) > $this->closeAbove;
    }

    private function areBlindsIn(string $state): bool
    {
        return $this->ha->getState($this->blinds)?->state === $state;
    }

    private function moveBlinds(string $action): void
    {
        $this->ha->callAction($action, target: ServiceTarget::forEntities($this->blinds));
    }
}
