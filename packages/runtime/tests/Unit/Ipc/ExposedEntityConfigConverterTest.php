<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\JsonShapeError;
use Stewart\Contracts\Exposure\BinarySensorConfig;
use Stewart\Contracts\Exposure\BinarySensorDeviceClass;
use Stewart\Contracts\Exposure\ButtonConfig;
use Stewart\Contracts\Exposure\ButtonDeviceClass;
use Stewart\Contracts\Exposure\EntityCategory;
use Stewart\Contracts\Exposure\NumberConfig;
use Stewart\Contracts\Exposure\NumberDeviceClass;
use Stewart\Contracts\Exposure\NumberMode;
use Stewart\Contracts\Exposure\SelectConfig;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SensorDeviceClass;
use Stewart\Contracts\Exposure\SwitchConfig;
use Stewart\Contracts\Exposure\SwitchDeviceClass;
use Stewart\Runtime\Ipc\Wire\ExposedEntityConfigConverter;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ExposedEntityConfigConverter::class)]
final class ExposedEntityConfigConverterTest extends TestCase
{
    use AssertsReason;

    public function testBinarySensorConfigRoundTrips(): void
    {
        $converter = new ExposedEntityConfigConverter();
        $config = new BinarySensorConfig(BinarySensorDeviceClass::Window, name: 'Window', entityCategory: EntityCategory::Diagnostic, enabledByDefault: false);

        self::assertEquals($config, $converter->decodeValue($converter->encodeValue($config), 'config'));
    }

    public function testSwitchConfigRoundTrips(): void
    {
        $converter = new ExposedEntityConfigConverter();
        $config = new SwitchConfig(SwitchDeviceClass::Outlet, name: 'Heater', icon: 'mdi:radiator');

        self::assertEquals($config, $converter->decodeValue($converter->encodeValue($config), 'config'));
    }

    public function testButtonConfigRoundTrips(): void
    {
        $converter = new ExposedEntityConfigConverter();
        $config = new ButtonConfig(ButtonDeviceClass::Restart, entityCategory: EntityCategory::Config);

        self::assertEquals($config, $converter->decodeValue($converter->encodeValue($config), 'config'));
    }

    public function testEnumSensorConfigRoundTrips(): void
    {
        $converter = new ExposedEntityConfigConverter();
        $config = new SensorConfig(SensorDeviceClass::Enum, options: ['low', 'high']);

        self::assertEquals($config, $converter->decodeValue($converter->encodeValue($config), 'config'));
    }

    public function testNumberConfigRoundTrips(): void
    {
        $converter = new ExposedEntityConfigConverter();
        $config = new NumberConfig(-3, 3, 0.5, NumberMode::Slider, NumberDeviceClass::Temperature, '°C', name: 'Target offset');

        self::assertEquals($config, $converter->decodeValue($converter->encodeValue($config), 'config'));
    }

    public function testSelectConfigRoundTrips(): void
    {
        $converter = new ExposedEntityConfigConverter();
        $config = new SelectConfig(['eco', 'comfort'], icon: 'mdi:radiator');

        self::assertEquals($config, $converter->decodeValue($converter->encodeValue($config), 'config'));
    }

    public function testUnknownPlatformIsRejected(): void
    {
        $this->assertThrowsReason(
            JsonShapeError::UnexpectedValue,
            static fn() => new ExposedEntityConfigConverter()->decodeValue(['platform' => 'light', 'enabled_by_default' => true], 'config'),
        );
    }

    public function testInvalidConfigIsRejected(): void
    {
        $this->assertThrowsReason(
            JsonShapeError::UnexpectedValue,
            static fn() => new ExposedEntityConfigConverter()->decodeValue(['platform' => 'sensor', 'icon' => 'thermometer', 'enabled_by_default' => true], 'config'),
        );
    }
}
