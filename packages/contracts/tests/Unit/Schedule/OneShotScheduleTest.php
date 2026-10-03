<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Schedule\OneShotSchedule;

#[CoversClass(OneShotSchedule::class)]
final class OneShotScheduleTest extends TestCase
{
    private const string ZONE = 'Europe/Budapest';

    public function testItOccursOnceAtItsMoment(): void
    {
        $moment = $this->createLocalMoment('2026-06-01 07:00:00');
        $schedule = OneShotSchedule::fromMoment($moment);

        $next = $schedule->findNextOccurrenceAfter($this->createLocalMoment('2026-06-01 06:00:00'));

        self::assertNotNull($next);
        self::assertSame($moment->getTimestamp(), $next->getTimestamp());
    }

    public function testItNeverOccursAgain(): void
    {
        $moment = $this->createLocalMoment('2026-06-01 07:00:00');

        self::assertNull(OneShotSchedule::fromMoment($moment)->findNextOccurrenceAfter($moment));
    }

    public function testMomentAlreadyPastNeverOccurs(): void
    {
        $schedule = OneShotSchedule::fromMoment($this->createLocalMoment('2026-06-01 07:00:00'));

        self::assertNull($schedule->findNextOccurrenceAfter($this->createLocalMoment('2026-06-01 08:00:00')));
    }

    public function testOccurrenceIsReportedInTheCallersTimezone(): void
    {
        $schedule = OneShotSchedule::fromMoment(new DateTimeImmutable('2026-06-01 07:00:00', new DateTimeZone('UTC')));

        $next = $schedule->findNextOccurrenceAfter($this->createLocalMoment('2026-06-01 06:00:00'));

        self::assertNotNull($next);
        self::assertSame('2026-06-01 09:00:00 +02:00', $next->format('Y-m-d H:i:s P'));
    }

    public function testItDescribesItself(): void
    {
        $schedule = OneShotSchedule::fromMoment($this->createLocalMoment('2026-06-01 07:00:00'));

        self::assertSame('once at 2026-06-01T07:00:00+02:00', $schedule->describe());
        self::assertFalse($schedule->isRecurring());
    }

    private function createLocalMoment(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new DateTimeZone(self::ZONE));
    }
}
