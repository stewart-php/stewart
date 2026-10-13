<?php

declare(strict_types=1);

namespace App\Tests;

use App\Thermostat;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Testing\App\AppHarness;

final class ThermostatTest extends TestCase
{
    public function testThermostatSurvivesTheStore(): void
    {
        $store = new AppHarness()->stores->openAppStore(new AppId('heating'));

        $store->set('thermostat', new Thermostat(21.5, 'heat'));

        self::assertEquals(new Thermostat(21.5, 'heat'), $store->getObject('thermostat', Thermostat::class));
    }
}
