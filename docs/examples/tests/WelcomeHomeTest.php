<?php

declare(strict_types=1);

namespace App\Tests;

use App\WelcomeHome;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Trigger\Collection\HaTriggerCollection;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Contracts\Trigger\ZoneTransition;
use Stewart\Testing\App\AppHarness;

final class WelcomeHomeTest extends TestCase
{
    public function testArrivingTurnsTheHallLightOn(): void
    {
        $harness = new AppHarness();
        $harness->startApp(WelcomeHome::class);

        $watched = HaTriggerCollection::fromList([
            HaTrigger::onZoneTransition('person.anna', 'zone.home', ZoneTransition::Enter, id: 'arrived'),
            HaTrigger::onZoneTransition('person.anna', 'zone.home', ZoneTransition::Leave, id: 'left'),
        ]);
        $harness->ha->pushTrigger($watched, new TriggerEvent(['platform' => 'zone', 'id' => 'arrived']));

        self::assertSame('light.turn_on', $harness->ha->getLastCall()->getServiceName());
        self::assertCount(1, $harness->ha->listWatchedTriggers());
    }
}
