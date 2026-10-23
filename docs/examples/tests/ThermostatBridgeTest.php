<?php

declare(strict_types=1);

namespace Automations\Tests;

use Automations\ThermostatBridge;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Testing\App\AppHarness;

final class ThermostatBridgeTest extends TestCase
{
    private AppHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new AppHarness();
        $this->harness->ha->seedState('climate.living_room', 'off');
        $this->harness->startApp(ThermostatBridge::class);
    }

    public function testTemperatureFromMqttSetsTheInputNumber(): void
    {
        $this->harness->mqtt->pushMessage(new MqttMessage('zigbee2mqtt/kitchen/temperature', '21.5'));

        $call = $this->harness->ha->getLastCall();
        self::assertSame('input_number.set_value', $call->getActionName());
        self::assertSame(['value' => 21.5], $call->data);
    }

    public function testClimateStateIsPublishedRetained(): void
    {
        $this->harness->ha->pushState('climate.living_room', 'heat');

        $message = $this->harness->mqtt->published->getLast();
        self::assertNotNull($message);
        self::assertSame('home/living_room/climate', $message->topic);
        self::assertSame(['state' => 'heat'], $message->decodeJsonPayload());
        self::assertSame(MqttQos::AtLeastOnce, $message->qos);
        self::assertTrue($message->retain);
    }
}
