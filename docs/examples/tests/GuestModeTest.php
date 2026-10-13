<?php

declare(strict_types=1);

namespace App\Tests;

use App\GuestMode;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exposure\Command\SwitchAction;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\State\EventContext;
use Stewart\Testing\App\AppHarness;

final class GuestModeTest extends TestCase
{
    public function testTurningGuestModeOnWarmsTheGuestRoomAndTellsOtherApps(): void
    {
        $harness = new AppHarness();
        $harness->startApp(GuestMode::class);

        $harness->entities->requireSwitch('guest_mode')->pushCommand(new SwitchCommand(SwitchAction::TurnOn, new EventContext('ui')));

        self::assertTrue($harness->stores->openGlobalStore()->getBool('guest_mode'));
        self::assertSame(['preset_mode' => 'comfort'], $harness->ha->getLastCall()->data);
    }
}
