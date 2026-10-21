<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Exposure\EntityExposure;
use Stewart\Contracts\Exposure\ExposedSensor;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SensorDeviceClass;
use Stewart\Contracts\Exposure\SensorStateClass;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\EntityState;

#[Automation(id: 'climate')]
final class AverageTemperature implements App
{
    private const array SOURCES = ['sensor.kitchen_temperature', 'sensor.hall_temperature'];

    private ExposedSensor $average;

    public function __construct(
        private readonly HaContext $ha,
        private readonly EntityExposure $entities,
    ) {}

    public function initialize(): void
    {
        $this->average = $this->entities->exposeSensor('average_temperature', new SensorConfig(
            deviceClass: SensorDeviceClass::Temperature,
            unit: '°C',
            stateClass: SensorStateClass::Measurement,
            displayPrecision: 1,
            name: 'Average temperature',
        ));

        $this->ha->watchStateChanges(Selector::anyOf(...self::SOURCES))->subscribe(function (): void {
            $readings = array_filter(array_map(fn(string $id) => $this->ha->getState($id), self::SOURCES), static fn(?EntityState $state) => $state !== null && is_numeric($state->state));
            $temperatures = array_map(static fn(EntityState $state) => (float) $state->state, $readings);

            $this->average->setValue($temperatures === [] ? null : array_sum($temperatures) / \count($temperatures), ['sources' => self::SOURCES]);
        });
    }
}
