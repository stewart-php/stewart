<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Mqtt;

use Stewart\Runtime\Config\MqttConfig;

interface MqttLinkFactory
{
    public function createMqttLink(MqttConfig $config): MqttLink;
}
