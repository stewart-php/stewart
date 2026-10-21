<?php

declare(strict_types=1);

namespace App\Tests;

use App\GuestMode;
use PHPUnit\Framework\TestCase;
use Stewart\Testing\App\AppHarness;

final class GuestModeTest extends TestCase
{
    public function testTurningGuestModeOnWarmsTheGuestRoomAndTellsOtherApps(): void
    {
        $harness = new AppHarness();
        $harness->startApp(GuestMode::class);

        $harness->entities->requireSwitch('guest_mode')->pushTurnOn();

        self::assertTrue($harness->stores->openGlobalStore()->getBool('guest_mode'));
        self::assertSame(['preset_mode' => 'comfort'], $harness->ha->getLastCall()->data);
    }
}
