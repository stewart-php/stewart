<?php

declare(strict_types=1);

namespace Automations\Tests;

use Automations\LightsOut;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class LightsOutTest extends TestCase
{
    public function testEveryListedLightTurnsOff(): void
    {
        $harness = new AppHarness();
        $harness->startApp(LightsOut::class, ['lights' => ['light.porch']]);

        $harness->advanceTime(Duration::minutes(23 * 60 + 30));

        $call = $harness->ha->getLastCall();
        self::assertSame('light.turn_off', $call->getActionName());
        self::assertSame(['light.porch'], $call->listTargetedEntityIds());
    }
}
