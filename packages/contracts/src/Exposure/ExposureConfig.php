<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\Exposure\Action\ActionConfig;
use Stewart\Contracts\Wire\WireVariants;

#[WireVariants('platform', [
    ExposedPlatform::Sensor->value => SensorConfig::class,
    ExposedPlatform::BinarySensor->value => BinarySensorConfig::class,
    ExposedPlatform::Switch->value => SwitchConfig::class,
    ExposedPlatform::Button->value => ButtonConfig::class,
    ExposedPlatform::Number->value => NumberConfig::class,
    ExposedPlatform::Select->value => SelectConfig::class,
    ExposedPlatform::Text->value => TextConfig::class,
    ExposedPlatform::Time->value => TimeConfig::class,
    ExposedPlatform::Date->value => DateConfig::class,
    ExposedPlatform::DateTime->value => DateTimeConfig::class,
    ExposedPlatform::Action->value => ActionConfig::class,
])]
interface ExposureConfig
{
    public function getPlatform(): ExposedPlatform;
}
