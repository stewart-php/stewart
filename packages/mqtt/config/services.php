<?php

declare(strict_types=1);

use Stewart\Mqtt\Connection\MqttConnector;
use Stewart\Mqtt\Packet\PacketDecoder;
use Stewart\Mqtt\Packet\PacketReader;
use Stewart\Mqtt\ReconnectingMqttLinkFactory;
use Stewart\Runtime\Broker\Mqtt\MqttLinkFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire();

    $services->set(PacketDecoder::class);
    $services->set(PacketReader::class);
    $services->set(MqttConnector::class);
    $services->set(ReconnectingMqttLinkFactory::class);
    $services->alias(MqttLinkFactory::class, ReconnectingMqttLinkFactory::class);
};
