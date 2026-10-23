<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Registry\EntityFilter;

#[Automation(id: 'room-motion')]
final readonly class RoomMotion implements App
{
    public function __construct(
        private HaContext $ha,
        private string $area = 'kitchen',
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges(EntityFilter::inArea($this->area)->withDomain('binary_sensor')->withLabel('motion'))
            ->whenChangedTo('on')
            ->subscribe(function (): void {
                $this->ha->callAction('light.turn_on', target: ActionTarget::forAreas($this->area));
            });
    }
}
