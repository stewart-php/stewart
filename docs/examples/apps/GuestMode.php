<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\EntityExposure;
use Stewart\Contracts\Exposure\SwitchConfig;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\Store\GlobalStore;
use Stewart\Contracts\Store\Store;

#[Automation(id: 'guest-mode')]
final readonly class GuestMode implements App
{
    public function __construct(
        private HaContext $ha,
        private EntityExposure $entities,
        #[GlobalStore]
        private Store $house,
        private string $guestRoomThermostat = 'climate.guest_room',
    ) {}

    public function initialize(): void
    {
        $switch = $this->entities->exposeSwitch('guest_mode', new SwitchConfig(name: 'Guest mode', icon: 'mdi:account-multiple'));
        $this->house->set('guest_mode', $switch->getValue() === true);

        $switch->watchCommands()->subscribe(function (SwitchCommand $command): void {
            $this->house->set('guest_mode', $command->isTurnOn());
            $this->ha->callService(
                'climate',
                'set_preset_mode',
                ['preset_mode' => $command->isTurnOn() ? 'comfort' : 'eco'],
                ServiceTarget::forEntities($this->guestRoomThermostat),
            );
        });
    }
}
