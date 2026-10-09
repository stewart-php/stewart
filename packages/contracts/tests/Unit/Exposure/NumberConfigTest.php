<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exposure;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ExposureError;
use Stewart\Contracts\Exposure\NumberConfig;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(NumberConfig::class)]
final class NumberConfigTest extends TestCase
{
    use AssertsReason;

    /** @param Closure(): mixed $build */
    #[DataProvider('provideInvalidConfigs')]
    public function testInvalidConfigIsRejected(Closure $build): void
    {
        self::assertThrowsReason(ExposureError::ConfigInvalid, $build);
    }

    /** @return iterable<string, array{Closure(): mixed}> */
    public static function provideInvalidConfigs(): iterable
    {
        yield 'min above max' => [static fn() => new NumberConfig(min: 5, max: 1)];
        yield 'zero step' => [static fn() => new NumberConfig(min: 0, max: 10, step: 0)];
        yield 'negative step' => [static fn() => new NumberConfig(min: 0, max: 10, step: -1)];
        yield 'infinite max' => [static fn() => new NumberConfig(min: 0, max: INF)];
        yield 'empty unit' => [static fn() => new NumberConfig(min: 0, max: 10, unit: '')];
    }

    public function testEqualMinAndMaxIsAccepted(): void
    {
        self::assertSame(3, new NumberConfig(min: 3, max: 3)->max);
    }

    public function testInfiniteStateIsRejected(): void
    {
        self::assertThrowsReason(ExposureError::StateInvalid, static fn() => new NumberConfig(min: 0, max: 10)->formatState(NAN));
    }

    public function testFiniteStateIsKeptAsIs(): void
    {
        self::assertSame(2.5, new NumberConfig(min: 0, max: 10)->formatState(2.5));
    }
}
