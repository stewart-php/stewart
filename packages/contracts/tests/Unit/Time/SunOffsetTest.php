<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Time;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
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

    public function testBeforeMovesEventTimeEarlier(): void
    {
        $sunset = Instant::fromIso('2026-10-05T16:40:00Z');

        self::assertSame('2026-10-05T16:10:00.000000Z', SunOffset::before(Duration::minutes(30))->applyTo($sunset)->toIso8601());
    }

    public function testAfterMovesEventTimeLater(): void
    {
        $sunrise = Instant::fromIso('2026-10-05T23:50:00Z');

        self::assertSame('2026-10-06T00:20:00.000000Z', SunOffset::after(Duration::minutes(30))->applyTo($sunrise)->toIso8601());
    }

    public function testNoneKeepsEventTime(): void
    {
        $sunrise = Instant::fromIso('2026-10-05T05:00:00Z');

        self::assertTrue(SunOffset::none()->applyTo($sunrise)->equals($sunrise));
    }
}
