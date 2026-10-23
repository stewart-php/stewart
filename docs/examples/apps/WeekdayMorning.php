<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Schedule\DayOfWeek;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\SunOffset;

#[Automation(id: 'weekday-morning')]
final readonly class WeekdayMorning implements App
{
    public function __construct(
        private HaContext $ha,
        private Scheduler $scheduler,
    ) {}

    public function initialize(): void
    {
        $this->scheduler->runDailyAt(
            '06:45',
            function (): void {
                $this->ha->callAction('switch.turn_on', target: ActionTarget::forEntities('switch.coffee_machine'));
            },
            DayOfWeek::Monday,
            DayOfWeek::Tuesday,
            DayOfWeek::Wednesday,
            DayOfWeek::Thursday,
            DayOfWeek::Friday,
        );

        $this->scheduler->runAtSunEvent(
            SunEvent::Sunset,
            function (): void {
                $this->ha->callAction('cover.close_cover', target: ActionTarget::forAreas('living_room'));
            },
            SunOffset::before(Duration::minutes(30)),
        );
    }
}
