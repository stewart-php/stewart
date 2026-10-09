<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Component\ExposedEntityDefinition;
use Stewart\Contracts\Exposure\BinarySensorConfig;
use Stewart\Contracts\Exposure\DeviceInfo;
use Stewart\Contracts\Exposure\EntityCategory;
use Stewart\Contracts\Exposure\ExposedPlatform;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SensorDeviceClass;

#[CoversClass(ExposedEntityDefinition::class)]
final class ExposedEntityDefinitionTest extends TestCase
{
    public function testEveryConfigFieldIsEncoded(): void
    {
        $config = new SensorConfig(
            SensorDeviceClass::Enum,
            options: ['low', 'high'],
            name: 'Tank level',
            icon: 'mdi:water',
            entityCategory: EntityCategory::Diagnostic,
            enabledByDefault: false,
        );

        $definition = ExposedEntityDefinition::fromConfig($config, null);

        self::assertSame(ExposedPlatform::Sensor, $definition->platform);
        self::assertSame([
            'name' => 'Tank level',
            'icon' => 'mdi:water',
            'entity_category' => 'diagnostic',
            'enabled_by_default' => false,
            'device_class' => 'enum',
            'options' => ['low', 'high'],
        ], $definition->config);
    }

    public function testDefaultConfigIsEmpty(): void
    {
        $definition = ExposedEntityDefinition::fromConfig(new BinarySensorConfig(), null);

        self::assertSame(['platform' => 'binary_sensor', 'config' => []], $definition->toMessageFields());
    }

    public function testDeviceKeepsOnlySetFields(): void
    {
        $definition = ExposedEntityDefinition::fromConfig(new SensorConfig(), new DeviceInfo('greenhouse', 'Greenhouse', suggestedArea: 'Garden'));

        self::assertSame(['identifier' => 'greenhouse', 'name' => 'Greenhouse', 'suggested_area' => 'Garden'], $definition->device);
    }
}
