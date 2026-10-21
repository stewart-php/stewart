<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Assembler;

use Stewart\Runtime\Broker\Mqtt\MqttConnectionTracker;
use Stewart\Runtime\Broker\Mqtt\MqttLink;
use Stewart\Runtime\Config\StewartConfig;
use Stewart\Runtime\Control\Protocol\Status\MqttStatus;

final readonly class MqttStatusBuilder
{
    public function __construct(
        private StewartConfig $config,
        private MqttConnectionTracker $tracker,
        private MqttLink $link,
    ) {}

    public function buildMqttStatus(): ?MqttStatus
    {
        return $this->config->mqtt === null ? null : $this->tracker->buildMqttStatus($this->link->countQueuedMessages());
    }
}
