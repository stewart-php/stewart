<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Tests\Fixtures\Analysed;

use Stewart\Contracts\HaContext;
use Stewart\PHPStan\Tests\Fixtures\OlderMajor\LightEntities;

final readonly class OlderMajorCalls
{
    public function __construct(
        private HaContext $ha,
        private LightEntities $lights,
    ) {}

    public function callThroughGeneratedClasses(): void
    {
        $this->ha->getState('light.kitchn');
        $this->ha->callAction('light.turn_onn');
        $this->lights->getEntity('light.kitchn')->getEntity()->callAction('turn_on');
    }
}
