<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'door-reminder')]
final class DoorReminder implements App
{
    private ?ScheduledTask $reminders = null;

    public function __construct(
        private readonly HaContext $ha,
        private readonly Scheduler $scheduler,
        private readonly string $door = 'binary_sensor.back_door',
        private readonly int $firstAfterMinutes = 5,
        private readonly int $repeatEveryMinutes = 10,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges($this->door)
            ->whenChangedTo('on', for: Duration::minutes($this->firstAfterMinutes))
            ->subscribe(function (): void {
                $this->remind();
                $this->reminders = $this->scheduler->runEvery(Duration::minutes($this->repeatEveryMinutes), $this->remind(...));
            });

        $this->ha->watchStateChanges($this->door)
            ->whenChangedTo('off')
            ->subscribe(function (): void {
                $this->reminders?->cancel();
                $this->reminders = null;
            });
    }

    private function remind(): void
    {
        $name = $this->ha->getState($this->door)?->getFriendlyName() ?? $this->door;
        $this->ha->callAction('notify.notify', ['title' => 'Door open', 'message' => "{$name} is still open."]);
    }
}
