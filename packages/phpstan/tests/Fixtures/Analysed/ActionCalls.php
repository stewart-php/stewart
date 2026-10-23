<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Tests\Fixtures\Analysed;

use Stewart\Contracts\Entity\Entity;
use Stewart\Contracts\HaContext;
use Stewart\PHPStan\Tests\Fixtures\Generated\LightEntities;

final readonly class ActionCalls
{
    public function __construct(
        private HaContext $ha,
        private LightEntities $lights,
        private Entity $entity,
        private string $configured,
    ) {}

    public function callActions(): void
    {
        $this->ha->callAction('light.turn_on');
        $this->ha->callAction('light.turn_onn');
        $this->ha->callActionForResponse('weather.get_forecasts');
        $this->ha->callAction('turn_on');
        $this->ha->callAction(action: 'notify.notfy');
        $this->ha->callAction($this->configured);
    }

    public function callEntityActions(): void
    {
        $this->ha->getEntity('light.hall')->callAction('turn_off');
        $this->ha->getEntity('light.hall')->callAction('toggle');
        $this->lights->getEntity('light.hall')->getEntity()->callAction('turn_onn');
        $this->entity->callAction('anything');
        $this->entity->callAction('light.turn_on');
    }
}
