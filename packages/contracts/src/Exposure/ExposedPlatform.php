<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

enum ExposedPlatform: string
{
    case Sensor = 'sensor';
    case BinarySensor = 'binary_sensor';
}
