<?php

declare(strict_types=1);

namespace App\Tests;

use App\BoostAction;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class BoostActionTest extends TestCase
{
    public function testBoostEndsAfterTheGivenMinutes(): void
    {
        $harness = new AppHarness();
        $harness->startApp(BoostAction::class);

        $harness->entities->requireAction('boost')->pushCall(['room' => 'climate.office', 'minutes' => 45]);
        self::assertSame(['preset_mode' => 'boost'], $harness->ha->getLastCall()->data);

        $harness->advanceTime(Duration::minutes(45));
        self::assertSame(['preset_mode' => 'comfort'], $harness->ha->getLastCall()->data);
    }
}
