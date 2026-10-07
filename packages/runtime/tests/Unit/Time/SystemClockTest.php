<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Time;

use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Time\SystemClock;
use Stewart\Support\Time\RevoltTimers;

use function Amp\delay;

#[CoversClass(SystemClock::class)]
final class SystemClockTest extends TestCase
{
    public function testItKeepsHomeAssistantsZoneNotTheContainers(): void
    {
        $clock = new SystemClock(new DateTimeZone('Asia/Kolkata'));

        self::assertSame('Asia/Kolkata', $clock->getTimeZone()->getName());
    }

    public function testBuildingItByNameLeavesThePhpDefaultZoneAlone(): void
    {
        $default = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            self::assertSame('America/New_York', new SystemClock(new DateTimeZone('America/New_York'))->getTimeZone()->getName());
            self::assertSame('UTC', date_default_timezone_get());
        } finally {
            date_default_timezone_set($default);
        }
    }

    public function testBothReadingsMoveForward(): void
    {
        $clock = SystemClock::inUtc();

        $wall = $clock->getNow();
        $monotonic = $clock->getMonotonicTime();
        usleep(2_000);

        self::assertFalse($clock->getNow()->isBefore($wall));
        self::assertGreaterThanOrEqual(2_000, $clock->getMonotonicTime()->elapsedSince($monotonic)->toMicroseconds());
    }

    public function testSystemMonotonicClockMovesWithTheTimers(): void
    {
        $clock = SystemClock::inUtc();
        $before = $clock->getMonotonicTime();
        $fired = null;

        new RevoltTimers()->startTimer(Duration::milliseconds(20), static function () use ($clock, &$fired): void {
            $fired = $clock->getMonotonicTime();
        });

        delay(0.025);

        self::assertNotNull($fired);
        self::assertGreaterThanOrEqual(20_000, $fired->elapsedSince($before)->toMicroseconds());
    }

    public function testIsWithinIncludesCurrentWallTime(): void
    {
        $clock = new SystemClock(new DateTimeZone('Asia/Kolkata'));
        $wall = $clock->getNow()->toDateTime($clock->getTimeZone());

        self::assertTrue($clock->isWithin($wall->modify('-1 hour')->format('H:i'), $wall->modify('+1 hour')->format('H:i')));
    }

    public function testIsWithinExcludesWindowLaterInDay(): void
    {
        $clock = new SystemClock(new DateTimeZone('Asia/Kolkata'));
        $wall = $clock->getNow()->toDateTime($clock->getTimeZone());

        self::assertFalse($clock->isWithin($wall->modify('+2 hours')->format('H:i'), $wall->modify('+3 hours')->format('H:i')));
    }
}
