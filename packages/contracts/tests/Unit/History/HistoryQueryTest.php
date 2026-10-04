<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\History;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\HistoryError;
use Stewart\Contracts\Exception\TimeError;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(HistoryQuery::class)]
#[CoversClass(HistoryWindow::class)]
final class HistoryQueryTest extends TestCase
{
    use AssertsReason;

    public function testLookbackEndsAtGivenNow(): void
    {
        $now = Instant::fromIso('2026-10-04T12:00:00Z');

        $window = HistoryQuery::lastFor(Duration::minutes(30))->resolveWindowAt($now);

        self::assertSame('2026-10-04T11:30:00.000000Z', (string) $window->startsAt);
        self::assertTrue($window->endsAt->equals($now));
        self::assertTrue($window->getDuration()->equals(Duration::minutes(30)));
    }

    public function testFixedWindowIgnoresNow(): void
    {
        $startsAt = Instant::fromIso('2026-10-04T08:00:00Z');
        $endsAt = Instant::fromIso('2026-10-04T09:00:00Z');

        $window = HistoryQuery::between($startsAt, $endsAt)->resolveWindowAt(Instant::fromIso('2026-10-05T00:00:00Z'));

        self::assertTrue($window->startsAt->equals($startsAt));
        self::assertTrue($window->endsAt->equals($endsAt));
    }

    public function testAttributesAreOptIn(): void
    {
        $query = HistoryQuery::lastFor(Duration::minutes(5));

        self::assertFalse($query->includesAttributes);
        self::assertTrue($query->withAttributes()->includesAttributes);
        self::assertFalse($query->includesAttributes);
    }

    public function testZeroLookbackIsRefused(): void
    {
        $this->assertThrowsReason(TimeError::DurationNotPositive, static fn() => HistoryQuery::lastFor(Duration::zero()));
    }

    public function testWindowEndingAtItsStartIsRefused(): void
    {
        $moment = Instant::fromIso('2026-10-04T08:00:00Z');

        $this->assertThrowsReason(HistoryError::WindowInvalid, static fn() => HistoryQuery::between($moment, $moment));
    }
}
