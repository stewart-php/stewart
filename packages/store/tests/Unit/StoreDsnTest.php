<?php

declare(strict_types=1);

namespace Stewart\Store\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Store\Exception\StoreSetupError;
use Stewart\Store\Exception\StoreSetupException;
use Stewart\Store\StoreDsn;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(StoreDsn::class)]
#[CoversClass(StoreSetupException::class)]
final class StoreDsnTest extends TestCase
{
    use AssertsReason;

    public function testSchemeIsWhatPicksABackend(): void
    {
        self::assertSame('redis', StoreDsn::parse('redis://valkey:6379/0')->scheme);
        self::assertSame('redis', StoreDsn::parse('REDIS://valkey:6379')->scheme);
    }

    public function testHostlessUrlKeepsItsScheme(): void
    {
        self::assertSame('unix', StoreDsn::parse('unix:///run/valkey.sock')->scheme);
    }

    public function testCastingToAStringHidesThePassword(): void
    {
        $dsn = StoreDsn::parse('redis://stewart:hunter2@valkey:6379/15?timeout=2');

        self::assertSame('redis://***:***@valkey:6379/15?timeout=2', (string) $dsn);
        self::assertStringNotContainsString('hunter2', (string) $dsn);
    }

    public function testUrlWithNothingToHideStillReadsBack(): void
    {
        self::assertSame('redis://valkey:6379', (string) StoreDsn::parse('redis://valkey:6379'));
    }

    public function testRevealingIsTheOnlyWayToTheRealThing(): void
    {
        $url = 'redis://stewart:hunter2@valkey:6379';

        self::assertSame($url, StoreDsn::parse($url)->reveal());
    }

    #[DataProvider('provideInvalidDsns')]
    public function testUrlWithoutSchemeIsRefusedUnechoed(string $dsn): void
    {
        $e = $this->assertThrowsReason(StoreSetupError::DsnInvalid, fn() => StoreDsn::parse($dsn));

        self::assertStringContainsString('expected a URL like "redis://host:6379/0"', $e->getMessage());
    }

    /** @return iterable<string, array{string}> */
    public static function provideInvalidDsns(): iterable
    {
        yield 'empty' => [''];
        yield 'a host alone' => ['valkey:6379'];
        yield 'a path alone' => ['/var/run/valkey.sock'];
    }

    public function testSecretInARefusedUrlDoesNotReachTheMessage(): void
    {
        try {
            StoreDsn::parse('hunter2');
        } catch (StoreSetupException $e) {
            self::assertStringNotContainsString('hunter2', $e->getMessage());

            return;
        }

        self::fail('That is not a URL.');
    }
}
