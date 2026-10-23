<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Tests\Fixtures\Analysed;

use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\PHPStan\Tests\Fixtures\Generated\LightEntities;

final readonly class EntityIdCalls
{
    public function __construct(
        private HaContext $ha,
        private Scheduler $scheduler,
        private LightEntities $lights,
        private string $configured,
    ) {}

    public function readStates(): void
    {
        $this->ha->getState('light.hall');
        $this->ha->getState('light.kitchn');
        $this->ha->getState('light.attic');
        $this->ha->getState(entityId: 'switch.fna');
        $this->ha->getState($this->configured);
        $this->ha->watchStateChanges('light.*');
        HaTrigger::atTime('07:30');
        HaTrigger::onZoneTransition('person.anna', 'zone.hom');
    }

    public function target(): ActionTarget
    {
        $this->scheduler->runAtEntityTime('input_datetime.wake_up', static function (): void {});
        new EntityId('light.hal');

        return ActionTarget::forEntities('light.hall', 'switch.fan', 'light.kitchne');
    }

    public function useGeneratedHandles(): void
    {
        $this->lights->getEntity('light.kitchen');
        $this->lights->getEntity('light.attic');
        $this->lights->getEntity('switch.fan');
        $this->lights->getEntity('light.porch');
    }

    public function readHeldStates(): void
    {
        $this->ha->getHeldStateDuration('light.kitchn');
    }
}
