<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;

#[CoversClass(ScheduledRun::class)]
final class ScheduledRunTest extends TestCase
{
    public function testLatenessIsGapBetweenOccurrenceAndFiring(): void
    {
        $run = $this->createScheduledRun('07:00:00.000', '07:00:01.250');

        self::assertSame(1_250, $run->getLateness()->toMilliseconds());
    }

    public function testFiringAheadOfItsOccurrenceIsNotNegativelyLate(): void
    {
        self::assertTrue($this->createScheduledRun('07:00:00.000', '06:59:59.990')->getLateness()->equals(Duration::zero()));
    }

    private function createScheduledRun(string $scheduledFor, string $firedAt): ScheduledRun
    {
        $zone = new DateTimeZone('Europe/Budapest');

        return new ScheduledRun(
            self::createStub(ScheduledTask::class),
            Instant::fromDateTime(new DateTimeImmutable('2026-06-01 ' . $scheduledFor, $zone)),
            Instant::fromDateTime(new DateTimeImmutable('2026-06-01 ' . $firedAt, $zone)),
            0,
        );
    }
}
