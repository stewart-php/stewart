<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Mqtt;

use Stewart\Runtime\Config\StewartConfig;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class MqttLinkResolver
{
    public function __construct(
        private StewartConfig $config,
        private DisabledMqttLink $disabledLink,
        private ?MqttLinkFactory $mqttLinkFactory = null,
    ) {}

    /** @throws ConfigurationException */
    public function resolveMqttLink(): MqttLink
    {
        if ($this->config->mqtt === null) {
            return $this->disabledLink;
        }

        if ($this->mqttLinkFactory === null) {
            throw ConfigurationException::mqttPackageMissing();
        }

        return $this->mqttLinkFactory->createMqttLink($this->config->mqtt);
    }
}
