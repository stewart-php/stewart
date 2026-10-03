<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Mqtt\Mqtt;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;

#[Automation(id: 'mqtt-relay')]
final readonly class MqttRelay implements App
{
    public const string FILTER = 'home/+/temp';

    public const string RELAY_TOPIC = 'home/relay';

    public function __construct(private Mqtt $mqtt) {}

    public function initialize(): void
    {
        $this->mqtt->watchMessages(self::FILTER)->subscribe(function (MqttMessage $message): void {
            $this->mqtt->publish(self::RELAY_TOPIC, ['from' => $message->topic, 'payload' => $message->payload], MqttQos::AtLeastOnce, true);
        });
    }

    public function dispose(): void {}
}
