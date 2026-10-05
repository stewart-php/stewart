<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Time;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\SunOffset;

#[CoversClass(SunOffset::class)]
final class SunOffsetTest extends TestCase
{
    public function testBeforeFormatsAsNegativeClock(): void
    {
        self::assertSame('-00:30:00', SunOffset::before(Duration::minutes(30))->formatAsHaOffset());
    }

    public function testAfterFormatsAsPositiveClock(): void
    {
        self::assertSame('01:15:00', SunOffset::after(Duration::minutes(75))->formatAsHaOffset());
    }

    public function testZeroMagnitudeCountsAsNone(): void
    {
        $offset = SunOffset::before(Duration::zero());

        self::assertTrue($offset->isNone());
        self::assertFalse($offset->isBeforeEvent());
        self::assertSame('00:00:00', $offset->formatAsHaOffset());
        self::assertTrue(SunOffset::none()->isNone());
    }

    public function testMagnitudeStaysUnsigned(): void
    {
        $offset = SunOffset::before(Duration::hours(2));

        self::assertTrue($offset->isBeforeEvent());
        self::assertTrue($offset->getMagnitude()->equals(Duration::hours(2)));
    }
}
