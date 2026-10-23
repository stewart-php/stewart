<?php

declare(strict_types=1);

namespace Automations;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;

#[Automation(id: 'doorbell-announcer')]
final readonly class DoorbellAnnouncer implements App
{
    public function __construct(
        private HaContext $ha,
        private string $button = 'binary_sensor.doorbell',
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges($this->button)
            ->whenChangedTo('on')
            ->subscribe(function (): void {
                $this->ha->fireEvent('doorbell_pressed', ['door' => 'front']);
            });
    }
}
