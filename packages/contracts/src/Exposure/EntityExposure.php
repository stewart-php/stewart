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

    /** @throws ExposureException */
    public function exposeText(ExposedEntityKey|string $key, TextConfig $config = new TextConfig(), ?DeviceInfo $device = null): ExposedText;

    /** @throws ExposureException */
    public function exposeTime(ExposedEntityKey|string $key, TimeConfig $config = new TimeConfig(), ?DeviceInfo $device = null): ExposedTime;

    /** @throws ExposureException */
    public function exposeDate(ExposedEntityKey|string $key, DateConfig $config = new DateConfig(), ?DeviceInfo $device = null): ExposedDate;

    /** @throws ExposureException */
    public function exposeDateTime(ExposedEntityKey|string $key, DateTimeConfig $config = new DateTimeConfig(), ?DeviceInfo $device = null): ExposedDateTime;
}
