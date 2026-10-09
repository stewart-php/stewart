<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exposure;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ExposureError;
use Stewart\Contracts\Exposure\TextConfig;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(TextConfig::class)]
final class TextConfigTest extends TestCase
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
        yield 'negative min' => [static fn() => new TextConfig(min: -1)];
        yield 'min above max' => [static fn() => new TextConfig(min: 10, max: 5)];
        yield 'max above state limit' => [static fn() => new TextConfig(max: 256)];
        yield 'empty pattern' => [static fn() => new TextConfig(pattern: '')];
    }

    public function testDefaultsAllowAnyStateLength(): void
    {
        $config = new TextConfig();

        self::assertSame([0, 255], [$config->min, $config->max]);
    }
}
