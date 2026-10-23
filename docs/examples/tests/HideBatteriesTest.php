<?php

declare(strict_types=1);

namespace Automations\Tests;

use Automations\HideBatteries;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Testing\App\AppHarness;

final class HideBatteriesTest extends TestCase
{
    public function testBatterySensorsAreHiddenAndLabelled(): void
    {
        $harness = new AppHarness();
        $harness->ha->registry->seedEntity(new RegisteredEntity(new EntityId('sensor.hall_battery')));
        $harness->ha->seedState('sensor.hall_battery', '80');

        $harness->startApp(HideBatteries::class);

        $entry = $harness->ha->registry->findEntity('sensor.hall_battery');
        self::assertTrue($entry?->isHidden());
        self::assertSame(['battery'], array_map(strval(...), $entry->labelIds));
    }
}
