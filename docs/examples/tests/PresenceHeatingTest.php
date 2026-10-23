<?php

declare(strict_types=1);

namespace Automations\Tests;

use Automations\PresenceHeating;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class PresenceHeatingTest extends TestCase
{
    private AppHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new AppHarness();
        $this->harness->ha->seedState('group.family', 'home');
        $this->harness->startApp(PresenceHeating::class);
    }

    public function testTheHeatingGoesToAwayOnceEveryoneHasLeft(): void
    {
        $this->harness->ha->pushState('group.family', 'not_home');
        $this->harness->advanceTime(Duration::minutes(15));

        $call = $this->harness->ha->getLastCall();
        self::assertSame(['preset_mode' => 'away'], $call->data);
        self::assertSame(['climate.living_room', 'climate.bedroom'], $call->listTargetedEntityIds());
    }

    public function testAQuickTripToTheShopChangesNothing(): void
    {
        $this->harness->ha->pushState('group.family', 'not_home');
        $this->harness->advanceTime(Duration::minutes(10));
        $this->harness->ha->pushState('group.family', 'home');

        self::assertSame(['preset_mode' => 'home'], $this->harness->ha->getLastCall()->data);
        self::assertCount(1, $this->harness->ha->calls);
    }
}
