<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

enum SensorStateClass: string
{
    case Measurement = 'measurement';
    case MeasurementAngle = 'measurement_angle';
    case Total = 'total';
    case TotalIncreasing = 'total_increasing';
}
