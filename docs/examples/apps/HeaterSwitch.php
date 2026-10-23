<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\EntityExposure;
use Stewart\Contracts\Exposure\SwitchConfig;
use Stewart\Contracts\Exposure\SwitchDeviceClass;
use Stewart\Contracts\HaContext;

#[Automation(id: 'heater')]
final readonly class HeaterSwitch implements App
{
    public function __construct(
        private HaContext $ha,
        private EntityExposure $entities,
        private string $plug = 'switch.heater_plug',
        private string $window = 'binary_sensor.living_room_window',
    ) {}

    public function initialize(): void
    {
        $heater = $this->entities->exposeSwitch('heater', new SwitchConfig(SwitchDeviceClass::Outlet, name: 'Heater'));

        $heater->watchCommands()->subscribe(function (SwitchCommand $command): void {
            if ($command->isTurnOn() && $this->ha->getState($this->window)?->state === 'on') {
                throw CommandException::rejected('Close the window first.');
            }

            $this->ha->callAction($command->isTurnOn() ? 'switch.turn_on' : 'switch.turn_off', target: ActionTarget::forEntities($this->plug));
        });
    }
}
