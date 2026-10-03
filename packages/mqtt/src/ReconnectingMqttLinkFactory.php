<?php

declare(strict_types=1);

namespace Stewart\Mqtt;

use Psr\Log\LoggerInterface;
use Stewart\Mqtt\Connection\MqttConnector;
use Stewart\Runtime\Broker\Mqtt\MqttLink;
use Stewart\Runtime\Broker\Mqtt\MqttLinkFactory;
use Stewart\Runtime\Config\MqttConfig;
use Stewart\Support\Time\Deadlines;

final readonly class ReconnectingMqttLinkFactory implements MqttLinkFactory
{
    public function __construct(
        private MqttConnector $connector,
        private Deadlines $deadlines,
        private LoggerInterface $logger,
    ) {}

    public function createMqttLink(MqttConfig $config): MqttLink
    {
        return new ReconnectingMqttLink($config, $this->connector, new OutboundMqttQueue($config->outboundBuffer), $this->deadlines, $this->logger);
    }
}
