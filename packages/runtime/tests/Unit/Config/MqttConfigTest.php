<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Runtime\Config\MqttConfig;
use Stewart\Runtime\Config\MqttServerUrl;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(MqttConfig::class)]
#[CoversClass(MqttServerUrl::class)]
final class MqttConfigTest extends TestCase
{
    use AssertsReason;

    public function testUnsetUrlDisablesMqtt(): void
    {
        self::assertNull(ConfigFixture::createStewartConfig()->mqtt);
    }

    public function testDefaultsApplyToMinimalSection(): void
    {
        $mqtt = ConfigFixture::createStewartConfig(['mqtt' => ['url' => 'mqtt://broker.local']])->mqtt;

        self::assertNotNull($mqtt);
        self::assertFalse($mqtt->serverUrl->usesTls);
        self::assertSame('tcp://broker.local:1883', $mqtt->serverUrl->toSocketUri());
        self::assertNull($mqtt->serverUrl->username);
        self::assertStringStartsWith('stewart-', $mqtt->clientId);
        self::assertSame(30.0, $mqtt->keepalive->toSeconds());
        self::assertSame(100, $mqtt->outboundBuffer);
        self::assertNull($mqtt->will);
    }

    public function testTlsUrlCarriesCredentialsAndDefaultPort(): void
    {
        $url = MqttServerUrl::parse('mqtts://home%40lab:s3cr%2Ft@broker.local');

        self::assertTrue($url->usesTls);
        self::assertSame(8883, $url->port);
        self::assertSame('home@lab', $url->username);
        self::assertSame('s3cr/t', $url->revealPassword());
        self::assertStringNotContainsString('s3cr', (string) $url);
    }

    public function testWillIsBuiltFromItsSection(): void
    {
        $mqtt = ConfigFixture::createStewartConfig(['mqtt' => [
            'url' => 'mqtt://broker.local:1884',
            'client_id' => 'stewart-test',
            'will' => ['topic' => 'stewart/status', 'payload' => 'offline', 'qos' => 1, 'retain' => true],
        ]])->mqtt;

        self::assertNotNull($mqtt?->will);
        self::assertSame('stewart-test', $mqtt->clientId);
        self::assertSame(1884, $mqtt->serverUrl->port);
        self::assertSame('stewart/status', $mqtt->will->topic);
        self::assertSame('offline', $mqtt->will->payload);
        self::assertSame(MqttQos::AtLeastOnce, $mqtt->will->qos);
        self::assertTrue($mqtt->will->retain);
    }

    public function testUnsupportedSchemeIsRejected(): void
    {
        self::assertThrowsReason(ConfigurationError::ValueInvalid, static fn() => MqttServerUrl::parse('ws://broker.local'));
    }
}
