<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Unit\Mqtt;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Testing\Mqtt\RecordingMqtt;

#[CoversClass(RecordingMqtt::class)]
final class RecordingMqttTest extends TestCase
{
    public function testPublishIsRecordedAsSent(): void
    {
        $mqtt = new RecordingMqtt();

        $mqtt->publish('home/light', ['on' => true], MqttQos::AtLeastOnce, true);

        $sent = $mqtt->published->getLast();
        self::assertSame('home/light', $sent?->topic);
        self::assertSame('{"on":true}', $sent->payload);
        self::assertTrue($sent->retain);
    }

    public function testWatchSeesOnlyMatchingMessages(): void
    {
        $mqtt = new RecordingMqtt();
        $seen = [];
        $mqtt->watchMessages('home/+/temp')->subscribe(static function (MqttMessage $message) use (&$seen): void {
            $seen[] = $message->topic;
        });

        $mqtt->pushMessage(new MqttMessage('home/hall/temp', '21'));
        $mqtt->pushMessage(new MqttMessage('home/hall/humidity', '40'));

        self::assertSame(['home/hall/temp'], $seen);
    }
}
