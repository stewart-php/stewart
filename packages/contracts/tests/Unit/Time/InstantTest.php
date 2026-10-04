<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Time;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\TimeError;
use Stewart\Contracts\Exception\TimeException;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(Instant::class)]
#[CoversClass(TimeException::class)]
final class InstantTest extends TestCase
{
    use AssertsReason;

    private string $defaultZone;

    protected function setUp(): void
    {
        $this->defaultZone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultZone);
    }

    #[DataProvider('provideIsoTexts')]
    public function testHomeAssistantsTimestampsAreRead(string $text, string $expected): void
    {
        self::assertSame($expected, Instant::fromIso($text)->toIso8601());
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideIsoTexts(): iterable
    {
        yield 'utc with microseconds' => ['2026-09-21T08:30:00.123456+00:00', '2026-09-21T08:30:00.123456Z'];
        yield 'utc without a fraction' => ['2026-09-21T08:30:00+00:00', '2026-09-21T08:30:00.000000Z'];
        yield 'an offset is folded in' => ['2026-09-21T10:30:00.5+02:00', '2026-09-21T08:30:00.500000Z'];
        yield 'zulu' => ['2026-09-21T08:30:00Z', '2026-09-21T08:30:00.000000Z'];
        yield 'no zone reads as utc' => ['2026-09-21T08:30:00', '2026-09-21T08:30:00.000000Z'];
    }

    #[DataProvider('provideUnparseableIsoTexts')]
    public function testAnythingThatIsNotAnIsoInstantIsRefused(string $text): void
    {
        $this->assertThrowsReason(TimeError::InstantUnparsable, fn() => Instant::fromIso($text));
    }

    /** @return iterable<string, array{string}> */
    public static function provideUnparseableIsoTexts(): iterable
    {
        yield 'a relative phrase PHP would happily accept' => ['yesterday'];
        yield 'not a time' => ['not a time'];
        yield 'an impossible date' => ['2026-13-45T08:30:00Z'];
        yield 'empty' => [''];
        yield 'trailing newline' => ["2026-09-21T08:30:00Z\n"];
    }

    public function testLenientReaderTurnsTheUnusableIntoNull(): void
    {
        self::assertNull(Instant::tryFromIso(null));
        self::assertNull(Instant::tryFromIso(''));
        self::assertNull(Instant::tryFromIso('yesterday-ish'));
        self::assertNotNull(Instant::tryFromIso('2026-09-21T08:30:00Z'));
    }

    public function testMicrosecondsSurviveTheTripThroughADateTime(): void
    {
        $moment = new DateTimeImmutable('2026-09-21 08:30:00.654321', new DateTimeZone('Europe/Budapest'));
        $instant = Instant::fromDateTime($moment);

        self::assertSame($moment->format('U.u'), $instant->toDateTime(new DateTimeZone('UTC'))->format('U.u'));
        self::assertSame($moment->getTimestamp() * 1_000_000 + 654_321, $instant->toEpochMicroseconds());
    }

    public function testMomentsBeforeTheEpochKeepTheirFraction(): void
    {
        $instant = Instant::fromEpochMicroseconds(-1_500_000);

        self::assertSame('1969-12-31T23:59:58.500000Z', $instant->toIso8601());
        self::assertTrue(Instant::fromDateTime($instant->toDateTime())->equals($instant));
    }

    public function testWithoutAZoneTheProcessDefaultIsUsed(): void
    {
        date_default_timezone_set('Europe/Budapest');

        self::assertSame('Europe/Budapest', Instant::fromIso('2026-09-21T08:30:00Z')->toDateTime()->getTimezone()->getName());
        self::assertSame('10:30', Instant::fromIso('2026-09-21T08:30:00Z')->toDateTime()->format('H:i'));
    }

    public function testArithmeticIsWithDurations(): void
    {
        $start = Instant::fromIso('2026-09-21T08:30:00Z');
        $later = $start->plus(Duration::milliseconds(1_500));

        self::assertSame('2026-09-21T08:30:01.500000Z', (string) $later);
        self::assertSame(1_500_000, $later->elapsedSince($start)->toMicroseconds());
        self::assertTrue($later->minus(Duration::milliseconds(1_500))->equals($start));
    }

    public function testElapsedTimeNeverGoesNegative(): void
    {
        $start = Instant::fromIso('2026-09-21T08:30:00Z');

        self::assertTrue($start->elapsedSince($start->plus(Duration::seconds(5)))->equals(Duration::zero()), 'A clock that stepped back is not negative lag.');
    }

    public function testInstantsOrder(): void
    {
        $early = Instant::fromEpochMicroseconds(1);
        $late = Instant::fromEpochMicroseconds(2);

        self::assertTrue($early->isBefore($late));
        self::assertTrue($late->isAfter($early));
        self::assertFalse($early->isAfter($early));
    }
}
