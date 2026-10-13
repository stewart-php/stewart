<?php

declare(strict_types=1);

namespace App\Tests;

use App\DoorbellAnnouncer;
use PHPUnit\Framework\TestCase;
use Stewart\Testing\App\AppHarness;

final class DoorbellAnnouncerTest extends TestCase
{
    public function testPressingTheDoorbellFiresAnEvent(): void
    {
        $harness = new AppHarness();
        $harness->ha->seedState('binary_sensor.doorbell', 'off');
        $harness->startApp(DoorbellAnnouncer::class);

        $harness->ha->pushState('binary_sensor.doorbell', 'on');

        $fired = $harness->ha->firedEvents->getLast();
        self::assertNotNull($fired);
        self::assertSame('doorbell_pressed', $fired->eventType);
        self::assertSame(['door' => 'front'], $fired->data);
    }
}
