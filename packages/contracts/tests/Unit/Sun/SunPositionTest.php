<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Sun;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Sun\SunPosition;

#[CoversClass(SunPosition::class)]
final class SunPositionTest extends TestCase
{
    public function testUpperLimbOnHorizonCountsAsUp(): void
    {
        self::assertTrue(new SunPosition(90.0, -0.2)->isAboveHorizon());
    }

    public function testSunBelowItsOwnRadiusCountsAsDown(): void
    {
        self::assertFalse(new SunPosition(270.0, -0.3)->isAboveHorizon());
    }
}
