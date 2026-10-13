<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Service\ServiceTarget;
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
                'arrived' => $this->ha->callService('light', 'turn_on', target: ServiceTarget::forEntities('light.hall')),
                'left' => $this->ha->callService('light', 'turn_off', target: ServiceTarget::forFloors('ground')),
                default => null,
            };
        });
    }

    public function dispose(): void {}

    private function createTriggers(): HaTriggerCollection
    {
        return HaTriggerCollection::fromList([
            HaTrigger::onZoneTransition($this->person, 'zone.home', ZoneTransition::Enter, id: 'arrived'),
            HaTrigger::onZoneTransition($this->person, 'zone.home', ZoneTransition::Leave, id: 'left'),
        ]);
    }
}
