<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\Exception\ExposureException;

interface EntityExposure
{
    /** @throws ExposureException */
    public function exposeSensor(ExposedEntityKey|string $key, SensorConfig $config = new SensorConfig(), ?DeviceInfo $device = null): ExposedSensor;

    /** @throws ExposureException */
    public function exposeBinarySensor(
        ExposedEntityKey|string $key,
        BinarySensorConfig $config = new BinarySensorConfig(),
        ?DeviceInfo $device = null,
    ): ExposedBinarySensor;
}
