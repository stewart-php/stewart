<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Time;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\MonotonicTime;

#[CoversClass(MonotonicTime::class)]
final class MonotonicTimeTest extends TestCase
{
    public function testReadingMovesByDurations(): void
    {
        $start = MonotonicTime::fromMicroseconds(1_000_000);
        $later = $start->plus(Duration::seconds(2));

        self::assertSame(3_000_000, $later->toMicroseconds());
        self::assertTrue($later->elapsedSince($start)->equals(Duration::seconds(2)));
        self::assertTrue($start->elapsedSince($later)->equals(Duration::zero()));
    }

    public function testReadingsOrder(): void
    {
        $early = MonotonicTime::fromMicroseconds(1);
        $late = MonotonicTime::fromMicroseconds(2);

        self::assertTrue($early->isBefore($late));
        self::assertTrue($late->isAfter($early));
        self::assertTrue($early->equals(MonotonicTime::fromMicroseconds(1)));
    }

    public function testSubMillisecondDurationsAreKept(): void
    {
        self::assertSame(5_900, MonotonicTime::fromMicroseconds(5_000)->plus(Duration::microseconds(900))->toMicroseconds());
    }
}
