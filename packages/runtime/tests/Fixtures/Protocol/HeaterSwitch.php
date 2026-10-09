<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\EntityExposure;

#[Automation(id: 'heater')]
final readonly class HeaterSwitch implements App
{
    public const string REJECTION = 'The heater stays on until morning.';

    public function __construct(private EntityExposure $entities) {}

    public function initialize(): void
    {
        $heater = $this->entities->exposeSwitch('heater');
        $heater->watchCommands()->subscribe(static function (SwitchCommand $command): void {
            if (!$command->isTurnOn()) {
                throw CommandException::rejected(self::REJECTION);
            }
        });
        $heater->setOff();
    }

    public function dispose(): void {}
}
