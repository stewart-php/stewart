<?php

declare(strict_types=1);

namespace App\Tests;

use App\ZigbeeRemote;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Testing\App\AppHarness;

final class ZigbeeRemoteTest extends TestCase
{
    private AppHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new AppHarness();
        $this->harness->startApp(ZigbeeRemote::class);
    }

    public function testBrightnessUpBrightensTheLight(): void
    {
        $this->harness->mqtt->pushMessage(new MqttMessage('zigbee2mqtt/living_room_remote/action', 'brightness_up'));

        $call = $this->harness->ha->getLastCall();
        self::assertSame('light.turn_on', $call->getActionName());
        self::assertSame(['brightness_step_pct' => 10], $call->data);
    }

    public function testUnknownActionsAreIgnored(): void
    {
        $this->harness->mqtt->pushMessage(new MqttMessage('zigbee2mqtt/living_room_remote/action', 'arrow_left_click'));

        self::assertTrue($this->harness->ha->calls->isEmpty());
    }
}
