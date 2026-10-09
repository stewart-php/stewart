<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exposure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ExposureError;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ExposedEntityKey::class)]
#[CoversClass(ExposureException::class)]
final class ExposedEntityKeyTest extends TestCase
{
    use AssertsReason;

    public function testStringIsParsedIntoKey(): void
    {
        self::assertTrue(ExposedEntityKey::fromKeyOrString('average_temperature')->equals(new ExposedEntityKey('average_temperature')));
    }

    #[DataProvider('provideInvalidKeys')]
    public function testInvalidKeyIsRejected(string $key): void
    {
        self::assertThrowsReason(ExposureError::KeyInvalid, static fn() => new ExposedEntityKey($key));
    }

    /** @return iterable<string, array{string}> */
    public static function provideInvalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'leading digit' => ['1st_floor'];
        yield 'dash' => ['average-temperature'];
        yield 'uppercase' => ['Average'];
        yield 'too long' => [str_repeat('a', 65)];
    }
}
