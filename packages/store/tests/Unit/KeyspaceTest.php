<?php

declare(strict_types=1);

namespace Stewart\Store\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StoreError;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Store\Keyspace;
use Stewart\Store\StorePrefix;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(Keyspace::class)]
#[CoversClass(StoreException::class)]
final class KeyspaceTest extends TestCase
{
    use AssertsReason;

    public function testAppKeysUseAppId(): void
    {
        $keyspace = Keyspace::forApp(new StorePrefix('stewart'), new AppId('heating'));

        self::assertSame('stewart:app:heating:', $keyspace->prefix);
        self::assertSame('stewart:app:heating:target', $keyspace->buildFullKey('target'));
    }

    public function testGlobalBucketSitsBesideApps(): void
    {
        self::assertSame('stewart:global:', Keyspace::forGlobalScope(new StorePrefix('stewart'))->prefix);
    }

    public function testRuntimeBucketSitsBesideApps(): void
    {
        self::assertSame('stewart:runtime:app-pause:heating', Keyspace::forRuntime(new StorePrefix('stewart'))->buildFullKey('app-pause:heating'));
    }

    public function testAppCalledRuntimeStaysApart(): void
    {
        $app = Keyspace::forApp(new StorePrefix('stewart'), new AppId('runtime'));

        self::assertFalse(str_starts_with($app->prefix, Keyspace::forRuntime(new StorePrefix('stewart'))->prefix));
    }

    public function testAppCalledGlobalStaysApart(): void
    {
        $app = Keyspace::forApp(new StorePrefix('stewart'), new AppId('global'));
        $shared = Keyspace::forGlobalScope(new StorePrefix('stewart'));

        self::assertNotSame($shared->prefix, $app->prefix);
        self::assertFalse(str_starts_with($app->prefix, $shared->prefix));
    }

    public function testPrefixesSeparateInstallations(): void
    {
        self::assertSame('upstairs:global:mode', Keyspace::forGlobalScope(new StorePrefix('upstairs'))->buildFullKey('mode'));
        self::assertSame('downstairs:global:mode', Keyspace::forGlobalScope(new StorePrefix('downstairs'))->buildFullKey('mode'));
    }

    public function testRelativeKeyUndoesFullKey(): void
    {
        $keyspace = Keyspace::forApp(new StorePrefix('stewart'), new AppId('heating'));

        self::assertSame('rooms:hall', $keyspace->extractRelativeKey($keyspace->buildFullKey('rooms:hall')));
    }

    #[DataProvider('provideValidKeys')]
    public function testAcceptsUsableKey(string $key): void
    {
        self::assertSame('stewart:global:' . $key, Keyspace::forGlobalScope(new StorePrefix('stewart'))->buildFullKey($key));
    }

    /** @return iterable<string, array{string}> */
    public static function provideValidKeys(): iterable
    {
        yield 'a word' => ['mode'];
        yield 'an entity id' => ['light.kitchen'];
        yield 'sub-namespaced' => ['rooms:hall:brightness'];
        yield 'at the length limit' => [str_repeat('a', 128)];
    }

    #[DataProvider('provideInvalidKeys')]
    public function testRejectsUnusableKey(string $key): void
    {
        $this->assertThrowsReason(StoreError::KeyInvalid, fn() => Keyspace::forGlobalScope(new StorePrefix('stewart'))->buildFullKey($key));
    }

    /** @return iterable<string, array{string}> */
    public static function provideInvalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'leading punctuation' => ['.hidden'];
        yield 'a glob star' => ['rooms:*'];
        yield 'a space' => ['last run'];
        yield 'a newline' => ["mode\n"];
        yield 'one over the limit' => [str_repeat('a', 129)];
    }
}
