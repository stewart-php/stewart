<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'door-alarm')]
final readonly class DoorAlarm implements App
{
    public function __construct(
        private HaContext $ha,
        private string $door,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges($this->door)
            ->whenChangedTo('on', for: Duration::minutes(5))
            ->subscribe(function (): void {
                $this->ha->callService('notify', 'notify', ['message' => 'The door has been open for 5 minutes.']);
            });
    }
}
