<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'south-blinds')]
final readonly class SouthBlinds implements App
{
    public function __construct(
        private HaContext $ha,
        private Scheduler $scheduler,
        private SunCalendar $sun,
        private string $blinds = 'cover.south_blinds',
    ) {}

    public function initialize(): void
    {
        $this->scheduler->runEvery(Duration::minutes(10), function (): void {
            $position = $this->sun->getCurrentPosition();
            $sunOnTheFacade = $position->isWithinAzimuth(135.0, 225.0) && $position->isHigherThan(15.0);

            if ($sunOnTheFacade && $this->ha->getState($this->blinds)?->state === 'open') {
                $this->ha->callAction('cover.close_cover', target: ServiceTarget::forEntities($this->blinds));
            }
        });
    }
}
