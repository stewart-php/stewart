<?php

declare(strict_types=1);

namespace App\Tests;

use App\AverageTemperature;
use PHPUnit\Framework\TestCase;
use Stewart\Testing\App\AppHarness;

final class AverageTemperatureTest extends TestCase
{
    public function testSensorShowsTheAverageOfNumericReadings(): void
    {
        $harness = new AppHarness();
        $harness->ha->seedState('sensor.kitchen_temperature', '20');
        $harness->ha->seedState('sensor.hall_temperature', 'unavailable');
        $harness->startApp(AverageTemperature::class);

        $harness->ha->pushState('sensor.hall_temperature', '23');

        self::assertSame(21.5, $harness->entities->requireSensor('average_temperature')->getValue());
    }
}
