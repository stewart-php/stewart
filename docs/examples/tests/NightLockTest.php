<?php

declare(strict_types=1);

namespace Automations\Tests;

use Automations\NightLock;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class NightLockTest extends TestCase
{
    private AppHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new AppHarness();
        $this->harness->ha->seedState('lock.front_door', 'unlocked');
        $this->harness->startApp(NightLock::class);
        $this->harness->advanceTime(Duration::hours(23));
    }

    public function testTheDoorIsLockedAtElevenPm(): void
    {
        self::assertSame('lock.lock', $this->harness->ha->getLastCall()->getActionName());
    }

    public function testNothingIsSentWhenTheDoorLocks(): void
    {
        $this->harness->ha->pushState('lock.front_door', 'locked');
        $this->harness->advanceTime(Duration::minutes(1));

        self::assertSame('lock.lock', $this->harness->ha->getLastCall()->getActionName());
    }

    public function testANotificationIsSentWhenTheDoorStaysUnlocked(): void
    {
        $this->harness->advanceTime(Duration::seconds(30));

        self::assertSame('notify.notify', $this->harness->ha->getLastCall()->getActionName());
    }
}
