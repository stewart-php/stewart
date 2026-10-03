<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Time;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\TimeError;
use Stewart\Contracts\Exception\TimeException;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(Duration::class)]
#[CoversClass(TimeException::class)]
final class DurationTest extends TestCase
{
    use AssertsReason;

    /** @param int|float $amount */
    #[DataProvider('provideUnitConversions')]
    public function testEveryUnitLandsOnTheSameMicrosecondCount(string $unit, int|float $amount, int $expected): void
    {
        $duration = match ($unit) {
            'microseconds' => Duration::microseconds((int) $amount),
            'milliseconds' => Duration::milliseconds($amount),
            'seconds' => Duration::seconds($amount),
            'minutes' => Duration::minutes($amount),
            default => Duration::hours($amount),
        };

        self::assertSame($expected, $duration->toMicroseconds());
    }

    /** @return iterable<string, array{string, int|float, int}> */
    public static function provideUnitConversions(): iterable
    {
        yield 'microseconds' => ['microseconds', 250, 250];
        yield 'milliseconds' => ['milliseconds', 300, 300_000];
        yield 'fractional milliseconds' => ['milliseconds', 12.5, 12_500];
        yield 'whole seconds' => ['seconds', 2, 2_000_000];
        yield 'fractional seconds' => ['seconds', 1.5, 1_500_000];
        yield 'minutes' => ['minutes', 5, 300_000_000];
        yield 'fractional minutes' => ['minutes', 0.5, 30_000_000];
        yield 'hours' => ['hours', 2, 7_200_000_000];
    }

    public function testCoarserReadingsAreDerived(): void
    {
        self::assertSame(0.3, Duration::milliseconds(300)->toSeconds());
        self::assertSame(90.0, Duration::minutes(1.5)->toSeconds());
        self::assertSame(1, Duration::microseconds(1_999)->toMilliseconds(), 'Milliseconds truncate.');
    }

    public function testZeroIsALength(): void
    {
        self::assertTrue(Duration::milliseconds(0)->equals(Duration::zero()));
        self::assertSame('0s', (string) Duration::zero());
    }

    public function testNegativeDurationIsRejected(): void
    {
        $this->assertThrowsReason(TimeError::DurationNegative, fn() => Duration::seconds(-1));
    }

    public function testInfinityIsRejected(): void
    {
        $this->assertThrowsReason(TimeError::DurationNotFinite, fn() => Duration::seconds(\INF));
    }

    public function testDurationBeyondTheIntegerRangeIsRejected(): void
    {
        $this->assertThrowsReason(TimeError::DurationTooLarge, fn() => Duration::hours(\PHP_INT_MAX));
    }

    public function testAddingBeyondTheIntegerRangeIsRejected(): void
    {
        $this->assertThrowsReason(TimeError::DurationTooLarge, fn() => Duration::microseconds(\PHP_INT_MAX)->plus(Duration::microseconds(1)));
    }

    public function testMeasurableDurationPassesThrough(): void
    {
        $window = Duration::milliseconds(1);

        self::assertSame($window, $window->requireAtLeastOneMillisecond('debounce'));
    }

    public function testLessThanAMillisecondIsNotMeasurable(): void
    {
        // A zero delay is a next-tick defer, which would turn debounce into tick-coalescing.
        $this->assertThrowsReason(TimeError::DurationNotPositive, fn() => Duration::microseconds(999)->requireAtLeastOneMillisecond('debounce'));
    }

    public function testEqualityAndOrderAreByLength(): void
    {
        self::assertTrue(Duration::seconds(1)->equals(Duration::milliseconds(1_000)));
        self::assertFalse(Duration::seconds(1)->equals(Duration::milliseconds(999)));
        self::assertTrue(Duration::seconds(1)->isLongerThan(Duration::milliseconds(999)));
        self::assertTrue(Duration::seconds(1)->plus(Duration::milliseconds(500))->equals(Duration::milliseconds(1_500)));
    }

    public function testItDescribesItselfInTheLargestUnitsThatFit(): void
    {
        self::assertSame('1h 30m', (string) Duration::minutes(90));
        self::assertSame('2s 500ms', (string) Duration::milliseconds(2_500));
        self::assertSame('250ms', (string) Duration::milliseconds(250));
        self::assertSame('1ms 500us', (string) Duration::microseconds(1_500));
    }

    #[DataProvider('provideReadableDurations')]
    public function testParsesEachNumberWithItsUnit(string $text, int $microseconds): void
    {
        self::assertSame($microseconds, Duration::parse($text)->toMicroseconds());
    }

    /** @return iterable<string, array{string, int}> */
    public static function provideReadableDurations(): iterable
    {
        yield 'microseconds' => ['750us', 750];
        yield 'milliseconds' => ['500ms', 500_000];
        yield 'seconds' => ['5s', 5_000_000];
        yield 'fractional seconds' => ['1.5s', 1_500_000];
        yield 'minutes' => ['2m', 120_000_000];
        yield 'hours' => ['1h', 3_600_000_000];
        yield 'zero' => ['0s', 0];
        yield 'surrounding space' => [' 10s ', 10_000_000];
        yield 'several segments' => ['1h 30m 5s', 5_405_000_000];
    }

    public function testDescriptionParsesBackToSameLength(): void
    {
        $duration = Duration::microseconds(5_405_001_500);

        self::assertTrue(Duration::parse((string) $duration)->equals($duration));
    }

    #[DataProvider('provideUnreadableDurations')]
    public function testAnythingElseIsRefused(string $text): void
    {
        $this->assertThrowsReason(TimeError::DurationUnparsable, fn() => Duration::parse($text));
    }

    /** @return iterable<string, array{string}> */
    public static function provideUnreadableDurations(): iterable
    {
        yield 'a bare number' => ['5'];
        yield 'a space before the unit' => ['5 s'];
        yield 'an unknown unit' => ['5d'];
        yield 'a negative amount' => ['-5s'];
        yield 'nothing' => [''];
    }
}
