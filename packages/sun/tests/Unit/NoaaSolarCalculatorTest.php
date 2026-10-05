<?php

declare(strict_types=1);

namespace Stewart\Sun\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Sun\NoaaSolarCalculator;

#[CoversClass(NoaaSolarCalculator::class)]
final class NoaaSolarCalculatorTest extends TestCase
{
    private const float CROSSING_TOLERANCE_SECONDS = 5.0;
    // Astral reads the equation of time at midnight for noon, this calculator at noon itself.
    private const float NOON_TOLERANCE_SECONDS = 20.0;
    private const float ANGLE_TOLERANCE_DEGREES = 0.01;

    private NoaaSolarCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new NoaaSolarCalculator();
    }

    /** @return iterable<string, array{GeoLocation, string, string, SunEvent, string}> */
    public static function provideAstralReferenceTimes(): iterable
    {
        $budapest = new GeoLocation(47.4979, 19.0402);
        $sydney = new GeoLocation(-33.8688, 151.2093);
        $tromso = new GeoLocation(69.6492, 18.9553);

        yield 'budapest sunrise on DST start' => [$budapest, 'Europe/Budapest', '2026-03-29', SunEvent::Sunrise, '2026-03-29T04:29:13Z'];
        yield 'budapest sunset on DST start' => [$budapest, 'Europe/Budapest', '2026-03-29', SunEvent::Sunset, '2026-03-29T17:08:50Z'];
        yield 'budapest civil dawn on DST start' => [$budapest, 'Europe/Budapest', '2026-03-29', SunEvent::CivilDawn, '2026-03-29T03:57:42Z'];
        yield 'budapest sunrise on DST end' => [$budapest, 'Europe/Budapest', '2026-10-25', SunEvent::Sunrise, '2026-10-25T05:17:07Z'];
        yield 'budapest sunset on DST end' => [$budapest, 'Europe/Budapest', '2026-10-25', SunEvent::Sunset, '2026-10-25T15:37:56Z'];
        yield 'budapest nautical dusk on DST end' => [$budapest, 'Europe/Budapest', '2026-10-25', SunEvent::NauticalDusk, '2026-10-25T16:45:49Z'];
        yield 'budapest astronomical dawn before UTC midnight' => [$budapest, 'Europe/Budapest', '2026-06-21', SunEvent::AstronomicalDawn, '2026-06-20T23:39:51Z'];
        yield 'budapest astronomical dusk in summer' => [$budapest, 'Europe/Budapest', '2026-06-21', SunEvent::AstronomicalDusk, '2026-06-21T21:51:25Z'];
        yield 'budapest golden hour evening start' => [$budapest, 'Europe/Budapest', '2026-12-21', SunEvent::GoldenHourEveningStart, '2026-12-21T14:03:26Z'];
        yield 'budapest blue hour morning end' => [$budapest, 'Europe/Budapest', '2026-12-21', SunEvent::BlueHourMorningEnd, '2026-12-21T06:05:45Z'];
        yield 'budapest hill sunrise' => [new GeoLocation(47.4979, 19.0402, 300.0), 'Europe/Budapest', '2026-10-05', SunEvent::Sunrise, '2026-10-05T04:45:46Z'];
        yield 'budapest hill sunset' => [new GeoLocation(47.4979, 19.0402, 300.0), 'Europe/Budapest', '2026-10-05', SunEvent::Sunset, '2026-10-05T16:17:57Z'];
        yield 'sydney sunrise on DST start' => [$sydney, 'Australia/Sydney', '2026-10-04', SunEvent::Sunrise, '2026-10-03T19:28:59Z'];
        yield 'sydney sunset on DST start' => [$sydney, 'Australia/Sydney', '2026-10-04', SunEvent::Sunset, '2026-10-04T07:59:35Z'];
        yield 'suva sunrise near dateline' => [new GeoLocation(-18.1416, 178.4419), 'Pacific/Fiji', '2026-07-01', SunEvent::Sunrise, '2026-06-30T18:38:32Z'];
        yield 'suva sunset near dateline' => [new GeoLocation(-18.1416, 178.4419), 'Pacific/Fiji', '2026-07-01', SunEvent::Sunset, '2026-07-01T05:41:35Z'];
        yield 'tromso golden hour morning end' => [$tromso, 'Europe/Oslo', '2026-03-01', SunEvent::GoldenHourMorningEnd, '2026-03-01T07:39:38Z'];
        yield 'tromso blue hour evening start' => [$tromso, 'Europe/Oslo', '2026-03-01', SunEvent::BlueHourEveningStart, '2026-03-01T16:23:20Z'];
        yield 'tromso civil dawn in polar night' => [$tromso, 'Europe/Oslo', '2026-12-21', SunEvent::CivilDawn, '2026-12-21T08:29:57Z'];
    }

    #[DataProvider('provideAstralReferenceTimes')]
    public function testCrossingMatchesAstral(GeoLocation $location, string $zone, string $date, SunEvent $event, string $expected): void
    {
        $actual = $this->calculator->findEventOn(new DateTimeImmutable($date, new DateTimeZone($zone)), $location, $event);

        self::assertNotNull($actual);
        self::assertEqualsWithDelta($this->toEpochSeconds(Instant::fromIso($expected)), $this->toEpochSeconds($actual), self::CROSSING_TOLERANCE_SECONDS);
    }

    public function testSolarNoonMatchesAstral(): void
    {
        $noon = $this->calculator->findSolarNoonOn(new DateTimeImmutable('2026-06-21', new DateTimeZone('Europe/Budapest')), new GeoLocation(47.4979, 19.0402));

        self::assertEqualsWithDelta($this->toEpochSeconds(Instant::fromIso('2026-06-21T10:45:33Z')), $this->toEpochSeconds($noon), self::NOON_TOLERANCE_SECONDS);
    }

    public function testMidnightSunHasNoSunset(): void
    {
        $midsummer = new DateTimeImmutable('2026-06-21', new DateTimeZone('Europe/Oslo'));
        $tromso = new GeoLocation(69.6492, 18.9553);

        self::assertNull($this->calculator->findEventOn($midsummer, $tromso, SunEvent::Sunset));
        self::assertNull($this->calculator->findEventOn($midsummer, $tromso, SunEvent::CivilDusk));
    }

    public function testPolarNightHasNoSunrise(): void
    {
        $midwinter = new DateTimeImmutable('2026-12-21', new DateTimeZone('Europe/Oslo'));

        self::assertNull($this->calculator->findEventOn($midwinter, new GeoLocation(69.6492, 18.9553), SunEvent::Sunrise));
    }

    public function testMidnightSunStillHasLowGoldenHour(): void
    {
        $midsummer = new DateTimeImmutable('2026-06-21', new DateTimeZone('Europe/Oslo'));

        self::assertNotNull($this->calculator->findEventOn($midsummer, new GeoLocation(69.6492, 18.9553), SunEvent::GoldenHourEveningStart));
    }

    /** @return iterable<string, array{GeoLocation, string, float, float}> */
    public static function provideAstralReferencePositions(): iterable
    {
        yield 'budapest summer morning' => [new GeoLocation(47.4979, 19.0402), '2026-06-21T10:00:00Z', 155.2807, 64.2799];
        yield 'budapest autumn afternoon' => [new GeoLocation(47.4979, 19.0402), '2026-10-05T15:00:00Z', 249.3601, 11.6537];
        yield 'sydney summer noon' => [new GeoLocation(-33.8688, 151.2093), '2026-01-15T02:00:00Z', 4.6727, 77.2422];
    }

    #[DataProvider('provideAstralReferencePositions')]
    public function testPositionMatchesAstral(GeoLocation $location, string $moment, float $azimuth, float $elevation): void
    {
        $position = $this->calculator->computePositionAt(Instant::fromIso($moment), $location);

        self::assertEqualsWithDelta($azimuth, $position->azimuthDegrees, self::ANGLE_TOLERANCE_DEGREES);
        self::assertEqualsWithDelta($elevation, $position->elevationDegrees, self::ANGLE_TOLERANCE_DEGREES);
    }

    public function testSunIsUpOnlyBetweenSunriseAndSunset(): void
    {
        $budapest = new GeoLocation(47.4979, 19.0402);
        $day = new DateTimeImmutable('2026-10-05', new DateTimeZone('Europe/Budapest'));
        $sunrise = $this->calculator->findEventOn($day, $budapest, SunEvent::Sunrise);
        $sunset = $this->calculator->findEventOn($day, $budapest, SunEvent::Sunset);
        self::assertNotNull($sunrise);
        self::assertNotNull($sunset);

        self::assertFalse($this->calculator->isSunUpAt($sunrise->minus(Duration::seconds(30)), $budapest));
        self::assertTrue($this->calculator->isSunUpAt($sunrise->plus(Duration::seconds(30)), $budapest));
        self::assertTrue($this->calculator->isSunUpAt($sunset->minus(Duration::seconds(30)), $budapest));
        self::assertFalse($this->calculator->isSunUpAt($sunset->plus(Duration::seconds(30)), $budapest));
    }

    private function toEpochSeconds(Instant $instant): float
    {
        return $instant->toEpochMicroseconds() / 1_000_000;
    }
}
