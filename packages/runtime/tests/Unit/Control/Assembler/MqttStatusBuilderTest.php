<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control\Assembler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Runtime\Broker\Mqtt\DisabledMqttLink;
use Stewart\Runtime\Broker\Mqtt\MqttConnectionTracker;
use Stewart\Runtime\Control\Assembler\MqttStatusBuilder;
use Stewart\Runtime\Lifecycle\ConnectionPhase;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(MqttStatusBuilder::class)]
final class MqttStatusBuilderTest extends TestCase
{
    public function testUnconfiguredMqttHasNoStatus(): void
    {
        self::assertNull($this->createBuilder([])->buildMqttStatus());
    }

    public function testConfiguredMqttReportsItsConnection(): void
    {
        self::assertSame(ConnectionPhase::Connecting, $this->createBuilder(['mqtt' => ['url' => 'mqtt://mosquitto:1883']])->buildMqttStatus()?->phase);
    }

    /** @param array<string, mixed> $yaml */
    private function createBuilder(array $yaml): MqttStatusBuilder
    {
        return new MqttStatusBuilder(ConfigFixture::createStewartConfig($yaml), new MqttConnectionTracker(new VirtualClock(), new NullLogger()), new DisabledMqttLink(new NullLogger()));
    }
}
