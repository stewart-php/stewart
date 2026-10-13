<?php

declare(strict_types=1);

namespace App\Tests;

use App\DoorReminder;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class DoorReminderTest extends TestCase
{
    private AppHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new AppHarness();
        $this->harness->ha->seedState('binary_sensor.back_door', 'off', ['friendly_name' => 'Back door']);
        $this->harness->startApp(DoorReminder::class);
    }

    public function testAnOpenDoorIsRemindedAboutUntilItCloses(): void
    {
        $this->harness->ha->pushState('binary_sensor.back_door', 'on', ['friendly_name' => 'Back door']);
        $this->harness->advanceTime(Duration::minutes(25));

        self::assertCount(3, $this->harness->ha->calls);
        self::assertSame('Back door is still open.', $this->harness->ha->getLastCall()->data['message'] ?? null);

        $this->harness->ha->pushState('binary_sensor.back_door', 'off', ['friendly_name' => 'Back door']);
        $this->harness->advanceTime(Duration::hours(1));

        self::assertCount(3, $this->harness->ha->calls);
    }
}
