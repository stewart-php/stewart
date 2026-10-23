<?php

declare(strict_types=1);

namespace Automations;

use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Trigger\Collection\HaTriggerCollection;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Contracts\Trigger\ZoneTransition;

#[Automation(id: 'welcome-home')]
final readonly class WelcomeHome implements App
{
    public function __construct(
        private HaContext $ha,
        private string $person = 'person.anna',
    ) {}

    public function initialize(): void
    {
        $this->ha->watchTrigger($this->createTriggers())->subscribe(function (TriggerEvent $event): void {
            match ($event->getTriggerId()) {
                'arrived' => $this->ha->callAction('light.turn_on', target: ActionTarget::forEntities('light.hall')),
                'left' => $this->ha->callAction('light.turn_off', target: ActionTarget::forFloors('ground')),
                default => null,
            };
        });
    }

    private function createTriggers(): HaTriggerCollection
    {
        return HaTriggerCollection::fromList([
            HaTrigger::onZoneTransition($this->person, 'zone.home', ZoneTransition::Enter, id: 'arrived'),
            HaTrigger::onZoneTransition($this->person, 'zone.home', ZoneTransition::Leave, id: 'left'),
        ]);
    }
}
