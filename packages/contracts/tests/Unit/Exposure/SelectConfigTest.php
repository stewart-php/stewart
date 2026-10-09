<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exposure;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ExposureError;
use Stewart\Contracts\Exposure\SelectConfig;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(SelectConfig::class)]
final class SelectConfigTest extends TestCase
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
        yield 'no options' => [static fn() => new SelectConfig([])];
        yield 'duplicate options' => [static fn() => new SelectConfig(['eco', 'eco'])];
        yield 'empty option' => [static fn() => new SelectConfig(['eco', ''])];
    }
}
