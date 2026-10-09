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

    /** @throws ExposureException */
    public function exposeSwitch(ExposedEntityKey|string $key, SwitchConfig $config = new SwitchConfig(), ?DeviceInfo $device = null): ExposedSwitch;

    /** @throws ExposureException */
    public function exposeButton(ExposedEntityKey|string $key, ButtonConfig $config = new ButtonConfig(), ?DeviceInfo $device = null): ExposedButton;

    /** @throws ExposureException */
    public function exposeNumber(ExposedEntityKey|string $key, NumberConfig $config, ?DeviceInfo $device = null): ExposedNumber;

    /** @throws ExposureException */
    public function exposeSelect(ExposedEntityKey|string $key, SelectConfig $config, ?DeviceInfo $device = null): ExposedSelect;
}
