<?php

declare(strict_types=1);

namespace Stewart\Contracts\Mqtt;

enum MqttQos: int
{
    case AtMostOnce = 0;
    case AtLeastOnce = 1;
}
