<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Unit\Time;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(VirtualClock::class)]
final class VirtualClockTest extends TestCase
{
    public function testWallTimeMovesWithTheTimers(): void
    {
        $timers = new ManualTimers();

        self::assertSame('2026-01-01 00:00:00', $timers->clock->getWallTime()->format('Y-m-d H:i:s'));

        $timers->delay(Duration::minutes(90));

        self::assertSame('2026-01-01 01:30:00', $timers->clock->getWallTime()->format('Y-m-d H:i:s'));
    }

    public function testGivenStartKeepsItsMomentAndItsZone(): void
    {
        $timers = new ManualTimers(new VirtualClock(new DateTimeImmutable('2026-06-01 07:00:00', new DateTimeZone('Europe/Budapest'))));

        $timers->delay(Duration::hours(1));

        self::assertSame('2026-06-01 08:00:00 +02:00', $timers->clock->getWallTime()->format('Y-m-d H:i:s P'));
        self::assertSame('Europe/Budapest', $timers->clock->getTimeZone()->getName());
    }

    public function testSkippingMovesWallTimeButNotMonotonic(): void
    {
        $clock = new VirtualClock();

        $clock->skip(Duration::hours(1));

        self::assertSame('2026-01-01 01:00:00', $clock->getWallTime()->format('Y-m-d H:i:s'));
        self::assertSame(0, $clock->getMonotonicTime()->toMicroseconds(), 'A suspended host leaves the monotonic clock behind.');
    }

    public function testRewindingTakesWallTimeBack(): void
    {
        $timers = new ManualTimers();

        $timers->delay(Duration::minutes(10));
        $timers->clock->rewind(Duration::minutes(15));

        self::assertSame('2025-12-31 23:55:00', $timers->clock->getWallTime()->format('Y-m-d H:i:s'));
        self::assertSame(600_000_000, $timers->clock->getMonotonicTime()->toMicroseconds());
    }
}
