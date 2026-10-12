<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Fixtures\App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'door-alarm')]
final class DoorAlarmApp implements App
{
    public private(set) bool $disposed = false;

    public function __construct(private readonly HaContext $ha) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges('binary_sensor.front_door')->whenChangedTo('on')->subscribe(function (): void {
            if ($this->ha->waitForState('binary_sensor.front_door', 'off', Duration::minutes(5)) === null) {
                $this->ha->callService('notify', 'notify', ['message' => 'The front door is still open']);
            }
        });
    }

    public function dispose(): void
    {
        $this->disposed = true;
    }
}
