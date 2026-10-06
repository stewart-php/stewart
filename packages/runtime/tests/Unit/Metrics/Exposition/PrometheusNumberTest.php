<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Metrics\Exposition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Metrics\Exposition\PrometheusNumber;

#[CoversClass(PrometheusNumber::class)]
final class PrometheusNumberTest extends TestCase
{
    /** @return iterable<string, array{float, string}> */
    public static function provideValues(): iterable
    {
        yield 'integral' => [42.0, '42'];
        yield 'negative integral' => [-3.0, '-3'];
        yield 'fraction' => [0.005, '0.005'];
        yield 'positive infinity' => [INF, '+Inf'];
        yield 'negative infinity' => [-INF, '-Inf'];
        yield 'not a number' => [NAN, 'NaN'];
        yield 'beyond exact integers' => [1.0e20, '1.0E+20'];
    }

    #[DataProvider('provideValues')]
    public function testFormatsValueForExposition(float $value, string $expected): void
    {
        self::assertSame($expected, PrometheusNumber::formatValue($value));
    }

    public function testInstantBecomesEpochSeconds(): void
    {
        self::assertSame(1758880800.5, PrometheusNumber::convertToEpochSeconds(Instant::fromEpochMicroseconds(1_758_880_800_500_000)));
    }
}
