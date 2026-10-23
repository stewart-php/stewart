<?php

declare(strict_types=1);

namespace Automations;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Registry\RegistryEditor;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;

#[Automation(id: 'hide-batteries')]
final readonly class HideBatteries implements App
{
    public function __construct(
        private HaContext $ha,
        private RegistryEditor $registry,
    ) {}

    public function initialize(): void
    {
        foreach ($this->ha->listStates('sensor.*_battery') as $battery) {
            $this->registry->updateEntity($battery->entityId, new EntityRegistryUpdate()
                ->withAddedLabels('battery')
                ->withHidden(true));
        }
    }
}
