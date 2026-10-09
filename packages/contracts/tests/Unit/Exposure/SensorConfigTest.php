<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exposure;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ExposureError;
use Stewart\Contracts\Exposure\ExposedEntityConfig;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SensorDeviceClass;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(SensorConfig::class)]
#[CoversClass(ExposedEntityConfig::class)]
#[CoversClass(SensorDeviceClass::class)]
final class SensorConfigTest extends TestCase
{
    use AssertsReason;

    /** @param Closure(): mixed $build */
    #[DataProvider('provideInvalidConfigs')]
    public function testInvalidConfigIsRejected(Closure $build): void
    {
        self::assertThrowsReason(ExposureError::ConfigInvalid, $build);
    }

    /** @return iterable<string, array{Closure(): mixed}> */
    public static function provideInvalidConfigs(): iterable
    {
        yield 'empty name' => [static fn() => new SensorConfig(name: '')];
        yield 'icon without mdi prefix' => [static fn() => new SensorConfig(icon: 'thermometer')];
        yield 'empty unit' => [static fn() => new SensorConfig(unit: '')];
        yield 'negative precision' => [static fn() => new SensorConfig(displayPrecision: -1)];
        yield 'enum without options' => [static fn() => new SensorConfig(deviceClass: SensorDeviceClass::Enum)];
        yield 'options without enum' => [static fn() => new SensorConfig(options: ['low', 'high'])];
        yield 'duplicate options' => [static fn() => new SensorConfig(deviceClass: SensorDeviceClass::Enum, options: ['low', 'low'])];
        yield 'empty option' => [static fn() => new SensorConfig(deviceClass: SensorDeviceClass::Enum, options: [''])];
    }

    public function testTimestampStateIsIsoWithOffset(): void
    {
        $config = new SensorConfig(deviceClass: SensorDeviceClass::Timestamp);

        self::assertSame('2026-10-09T07:30:00+02:00', $config->formatState(new DateTimeImmutable('2026-10-09 07:30:00+02:00')));
    }

    public function testDateStateIsCalendarDate(): void
    {
        $config = new SensorConfig(deviceClass: SensorDeviceClass::Date);

        self::assertSame('2026-10-09', $config->formatState(new DateTimeImmutable('2026-10-09 23:00:00+02:00')));
    }

    public function testScalarStateIsKeptAsIs(): void
    {
        self::assertSame(21.4, new SensorConfig(unit: '°C')->formatState(21.4));
    }

    public function testDateTimeWithoutDateClassIsRejected(): void
    {
        self::assertThrowsReason(ExposureError::StateInvalid, static fn() => new SensorConfig()->formatState(new DateTimeImmutable()));
    }

    public function testNonFiniteStateIsRejected(): void
    {
        self::assertThrowsReason(ExposureError::StateInvalid, static fn() => new SensorConfig()->formatState(\NAN));
    }
}
