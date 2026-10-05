<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Sun;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Sun\SunPosition;

#[CoversClass(SunPosition::class)]
final class SunPositionTest extends TestCase
{
    public function testHigherThanIsStrict(): void
    {
        $position = new SunPosition(180.0, 15.0);

        self::assertTrue($position->isHigherThan(14.9));
        self::assertFalse($position->isHigherThan(15.0));
    }

    public function testNegativeThresholdCoversTwilight(): void
    {
        self::assertTrue(new SunPosition(90.0, -3.0)->isHigherThan(-6.0));
    }

    /** @return iterable<string, array{float, float, float, bool}> */
    public static function provideAzimuthSectors(): iterable
    {
        yield 'inside south sector' => [180.0, 135.0, 225.0, true];
        yield 'on sector edge' => [135.0, 135.0, 225.0, true];
        yield 'outside south sector' => [90.0, 135.0, 225.0, false];
        yield 'north sector east of north' => [20.0, 315.0, 45.0, true];
        yield 'north sector west of north' => [340.0, 315.0, 45.0, true];
        yield 'outside north sector' => [180.0, 315.0, 45.0, false];
        yield 'bounds beyond full turn' => [10.0, -45.0, 405.0, true];
    }

    #[DataProvider('provideAzimuthSectors')]
    public function testAzimuthSectorWrapsPastNorth(float $azimuth, float $from, float $to, bool $expected): void
    {
        self::assertSame($expected, new SunPosition($azimuth, 30.0)->isWithinAzimuth($from, $to));
    }
}
