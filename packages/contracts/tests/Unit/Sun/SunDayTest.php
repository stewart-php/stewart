<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Sun;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Sun\SunDay;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Time\Instant;

#[CoversClass(SunDay::class)]
final class SunDayTest extends TestCase
{
    public function testDateIsTruncatedToMidnightInItsZone(): void
    {
        $day = SunDay::forDate(new DateTimeImmutable('2026-10-05 14:30', new DateTimeZone('Europe/Budapest')));

        self::assertSame('2026-10-05T00:00:00+02:00', $day->date->format(DATE_ATOM));
    }

    public function testRecordedEventTimeIsFound(): void
    {
        $sunrise = Instant::fromIso('2026-10-05T05:00:00Z');

        $day = SunDay::forDate(new DateTimeImmutable('2026-10-05'))->withEventTime(SunEvent::Sunrise, $sunrise);

        self::assertSame($sunrise, $day->findEventTime(SunEvent::Sunrise));
    }

    public function testEventThatDidNotOccurIsNull(): void
    {
        self::assertNull(SunDay::forDate(new DateTimeImmutable('2026-06-21'))->findEventTime(SunEvent::AstronomicalDusk));
    }

    public function testRecordingLeavesOriginalUntouched(): void
    {
        $day = SunDay::forDate(new DateTimeImmutable('2026-10-05'));

        $day->withEventTime(SunEvent::Sunset, Instant::fromIso('2026-10-05T16:40:00Z'));

        self::assertNull($day->findEventTime(SunEvent::Sunset));
    }
}
