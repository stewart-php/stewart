<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Mqtt\Mqtt;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Contracts\State\StateChange;

#[Automation(id: 'thermostat-bridge')]
final readonly class ThermostatBridge implements App
{
    public function __construct(
        private HaContext $ha,
        private Mqtt $mqtt,
    ) {}

    public function initialize(): void
    {
        $this->mqtt->watchMessages('zigbee2mqtt/+/temperature')->subscribe(function (MqttMessage $message): void {
            $this->ha->callAction('input_number.set_value', ['value' => (float) $message->payload]);
        });

        $this->ha->watchStateChanges('climate.living_room')->subscribe(function (StateChange $change): void {
            $this->mqtt->publish('home/living_room/climate', ['state' => $change->to?->state], MqttQos::AtLeastOnce, retain: true);
        });
    }
}
