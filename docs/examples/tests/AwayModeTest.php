<?php

declare(strict_types=1);

namespace App\Tests;

use App\AwayMode;
use PHPUnit\Framework\TestCase;
use Stewart\Testing\App\AppHarness;

final class AwayModeTest extends TestCase
{
    public function testTheHouseModeIsSharedWithEveryApp(): void
    {
        $harness = new AppHarness();
        $harness->ha->seedState('group.family', 'home');
        $harness->startApp(AwayMode::class);

        $harness->ha->pushState('group.family', 'not_home');

        self::assertSame('away', $harness->stores->openGlobalStore()->getString('mode'));
    }
}
