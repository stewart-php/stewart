<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Mqtt;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Broker\Mqtt\DisabledMqttLink;
use Stewart\Runtime\Broker\Mqtt\MqttLink;
use Stewart\Runtime\Broker\Mqtt\MqttLinkFactory;
use Stewart\Runtime\Broker\Mqtt\MqttLinkResolver;
use Stewart\Runtime\Config\MqttConfig;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Tests\Fixtures\Broker\Mqtt\RecordingMqttLink;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(MqttLinkResolver::class)]
final class MqttLinkResolverTest extends TestCase
{
    use AssertsReason;

    private const array MQTT_YAML = ['mqtt' => ['url' => 'mqtt://broker.local']];

    public function testUnsetUrlResolvesDisabledLink(): void
    {
        $disabled = new DisabledMqttLink(new RecordingLogger());
        $resolver = new MqttLinkResolver(ConfigFixture::createStewartConfig(), $disabled, self::createFactory(new RecordingMqttLink()));

        self::assertSame($disabled, $resolver->resolveMqttLink());
    }

    public function testConfiguredUrlUsesInstalledFactory(): void
    {
        $link = new RecordingMqttLink();
        $resolver = new MqttLinkResolver(ConfigFixture::createStewartConfig(self::MQTT_YAML), new DisabledMqttLink(new RecordingLogger()), self::createFactory($link));

        self::assertSame($link, $resolver->resolveMqttLink());
    }

    public function testConfiguredUrlWithoutFactoryFails(): void
    {
        $resolver = new MqttLinkResolver(ConfigFixture::createStewartConfig(self::MQTT_YAML), new DisabledMqttLink(new RecordingLogger()));

        self::assertThrowsReason(ConfigurationError::MqttPackageMissing, $resolver->resolveMqttLink(...));
    }

    private static function createFactory(MqttLink $link): MqttLinkFactory
    {
        return new readonly class ($link) implements MqttLinkFactory {
            public function __construct(private MqttLink $link) {}

            public function createMqttLink(MqttConfig $config): MqttLink
            {
                return $this->link;
            }
        };
    }
}
