<?php

declare(strict_types=1);

namespace Stewart\Sun\Tests\Unit;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\SunError;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Time\Instant;
use Stewart\Sun\UnlocatedSunCalendar;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(UnlocatedSunCalendar::class)]
final class UnlocatedSunCalendarTest extends TestCase
{
    use AssertsReason;

    /** @return iterable<string, array{Closure(UnlocatedSunCalendar): mixed}> */
    public static function provideQueries(): iterable
    {
        yield 'location' => [static fn(UnlocatedSunCalendar $calendar): mixed => $calendar->getLocation()];
        yield 'next event' => [static fn(UnlocatedSunCalendar $calendar): mixed => $calendar->findNextEvent(SunEvent::Sunrise)];
        yield 'day' => [static fn(UnlocatedSunCalendar $calendar): mixed => $calendar->getDayOn(new DateTimeImmutable())];
        yield 'position' => [static fn(UnlocatedSunCalendar $calendar): mixed => $calendar->getPositionAt(Instant::fromEpochMicroseconds(0))];
        yield 'current position' => [static fn(UnlocatedSunCalendar $calendar): mixed => $calendar->getCurrentPosition()];
        yield 'sun up' => [static fn(UnlocatedSunCalendar $calendar): mixed => $calendar->isSunUp()];
    }

    /** @param Closure(UnlocatedSunCalendar): mixed $query */
    #[DataProvider('provideQueries')]
    public function testEveryQueryReportsUnknownLocation(Closure $query): void
    {
        $this->assertThrowsReason(SunError::LocationUnknown, static fn(): mixed => $query(new UnlocatedSunCalendar()));
    }
}
