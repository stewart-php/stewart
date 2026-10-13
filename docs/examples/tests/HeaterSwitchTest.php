<?php

declare(strict_types=1);

namespace App\Tests;

use App\HeaterSwitch;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exposure\Command\SwitchAction;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\State\EventContext;
use Stewart\Testing\App\AppHarness;

final class HeaterSwitchTest extends TestCase
{
    private AppHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new AppHarness();
        $this->harness->ha->seedState('binary_sensor.living_room_window', 'off');
        $this->harness->startApp(HeaterSwitch::class);
    }

    public function testTurningTheSwitchOnTurnsThePlugOn(): void
    {
        $this->harness->entities->requireSwitch('heater')->pushCommand(new SwitchCommand(SwitchAction::TurnOn, new EventContext('ui')));

        self::assertSame('switch.turn_on', $this->harness->ha->getLastCall()->getServiceName());
        self::assertTrue($this->harness->entities->requireSwitch('heater')->getValue());
    }

    public function testAnOpenWindowRefusesTheHeater(): void
    {
        $this->harness->ha->pushState('binary_sensor.living_room_window', 'on');

        $this->expectException(CommandException::class);
        $this->harness->entities->requireSwitch('heater')->pushCommand(new SwitchCommand(SwitchAction::TurnOn, new EventContext('ui')));
    }
}
