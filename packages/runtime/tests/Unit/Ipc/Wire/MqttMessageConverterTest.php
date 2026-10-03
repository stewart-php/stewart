<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc\Wire;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\JsonShapeError;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Runtime\Ipc\Wire\MqttMessageConverter;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(MqttMessageConverter::class)]
final class MqttMessageConverterTest extends TestCase
{
    use AssertsReason;

    public function testBinaryPayloadSurvivesRoundTrip(): void
    {
        $converter = new MqttMessageConverter();
        $message = new MqttMessage('home/raw', "\x00\xff\x80", MqttQos::AtLeastOnce, true);

        $decoded = $converter->decodeValue($converter->encodeValue($message), 'message');

        self::assertEquals($message, $decoded);
    }

    /** @param array<string, mixed> $encoded */
    #[DataProvider('provideBrokenMessages')]
    public function testBrokenMessageIsRejected(array $encoded, JsonShapeError $reason): void
    {
        self::assertThrowsReason($reason, static fn() => new MqttMessageConverter()->decodeValue($encoded, 'message'));
    }

    /** @return iterable<string, array{array<string, mixed>, JsonShapeError}> */
    public static function provideBrokenMessages(): iterable
    {
        $valid = ['topic' => 'home/a', 'payload_base64' => 'b24=', 'qos' => 0, 'retain' => false];

        yield 'payload not base64' => [['payload_base64' => '%%%'] + $valid, JsonShapeError::UnexpectedValue];
        yield 'quality of service 2' => [['qos' => 2] + $valid, JsonShapeError::UnexpectedValue];
        yield 'retain not a boolean' => [['retain' => 'yes'] + $valid, JsonShapeError::WrongType];
        yield 'topic missing' => [array_diff_key($valid, ['topic' => true]), JsonShapeError::WrongType];
    }
}
