<?php

declare(strict_types=1);

namespace Stewart\Store\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Store\Exception\StoreSetupError;
use Stewart\Store\StorePrefix;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(StorePrefix::class)]
final class StorePrefixTest extends TestCase
{
    use AssertsReason;

    public function testPlainNameIsKept(): void
    {
        self::assertSame('upstairs-2.home_a', new StorePrefix('upstairs-2.home_a')->value);
    }

    #[DataProvider('provideInvalidPrefixes')]
    public function testPrefixThatWouldMatchTooMuchOrNothingIsRefused(string $prefix): void
    {
        $this->assertThrowsReason(StoreSetupError::PrefixInvalid, fn() => new StorePrefix($prefix));
    }

    /** @return iterable<string, array{string}> */
    public static function provideInvalidPrefixes(): iterable
    {
        yield 'glob' => ['up*'];
        yield 'empty' => [''];
        yield 'leading dot' => ['.hidden'];
        yield 'separator' => ['a:b'];
        yield 'too long' => [str_repeat('a', 65)];
    }
}
