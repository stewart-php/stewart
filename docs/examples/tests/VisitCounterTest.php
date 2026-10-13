<?php

declare(strict_types=1);

namespace App\Tests;

use App\VisitCounter;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Testing\App\AppHarness;

final class VisitCounterTest extends TestCase
{
    public function testACorruptCountStartsAgain(): void
    {
        $harness = new AppHarness();
        $store = $harness->stores->openAppStore(new AppId('visit-counter'));
        $store->set('visits', 'many');
        $harness->ha->seedState('binary_sensor.doorbell', 'off');
        $harness->startApp(VisitCounter::class);

        $harness->ha->pushState('binary_sensor.doorbell', 'on');

        self::assertSame(1, $store->getInt('visits'));
        self::assertCount(1, $harness->logger->listMessagesAt('warning'));
    }
}
