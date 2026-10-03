<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Mqtt;

use Stewart\Contracts\Mqtt\MqttMessage;

interface MqttMessageRouter
{
    public function routeMqttMessage(MqttMessage $message): void;
}
