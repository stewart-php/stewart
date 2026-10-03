<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Mqtt;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\MqttError;
use Stewart\Contracts\Exception\MqttException;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(MqttMessage::class)]
#[CoversClass(MqttException::class)]
final class MqttMessageTest extends TestCase
{
    use AssertsReason;

    public function testArrayPayloadIsPublishedAsJson(): void
    {
        $message = MqttMessage::createForPublish('home/light', ['on' => true, 'path' => 'a/b'], MqttQos::AtLeastOnce, true);

        self::assertSame('{"on":true,"path":"a/b"}', $message->payload);
        self::assertSame(MqttQos::AtLeastOnce, $message->qos);
        self::assertTrue($message->retain);
    }

    public function testStringPayloadIsPublishedVerbatim(): void
    {
        self::assertSame("\x00\xff", MqttMessage::createForPublish('raw', "\x00\xff", MqttQos::AtMostOnce, false)->payload);
    }

    #[DataProvider('provideInvalidTopics')]
    public function testInvalidTopicIsRejected(string $topic): void
    {
        self::assertThrowsReason(MqttError::TopicInvalid, static fn() => MqttMessage::createForPublish($topic, '', MqttQos::AtMostOnce, false));
    }

    /** @return iterable<string, array{string}> */
    public static function provideInvalidTopics(): iterable
    {
        yield 'empty' => [''];
        yield 'single level wildcard' => ['home/+'];
        yield 'multi level wildcard' => ['home/#'];
        yield 'nul character' => ["home\0"];
    }

    public function testUnencodablePayloadIsRejected(): void
    {
        self::assertThrowsReason(
            MqttError::PayloadUnencodable,
            static fn() => MqttMessage::createForPublish('home', ['value' => \NAN], MqttQos::AtMostOnce, false),
        );
    }

    public function testJsonPayloadDecodesToArray(): void
    {
        self::assertSame(['t' => 21.5], new MqttMessage('home/temp', '{"t":21.5}')->decodeJsonPayload());
    }

    public function testNonJsonPayloadFailsToDecode(): void
    {
        self::assertThrowsReason(MqttError::PayloadNotJson, static fn() => new MqttMessage('home/temp', 'on')->decodeJsonPayload());
    }
}
