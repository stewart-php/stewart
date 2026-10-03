<?php

declare(strict_types=1);

namespace Stewart\Support\Tests\Unit\Url;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Support\Url\UrlRedactor;

#[CoversClass(UrlRedactor::class)]
final class UrlRedactorTest extends TestCase
{
    #[DataProvider('provideUrls')]
    public function testCredentialsAreMaskedAndTheRestIsKept(string $url, string $expected): void
    {
        self::assertSame($expected, UrlRedactor::redactCredentials($url));
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideUrls(): iterable
    {
        yield 'user and password' => ['redis://stewart:hunter2@valkey:6379/15', 'redis://***:***@valkey:6379/15'];
        yield 'user alone' => ['redis://stewart@valkey:6379', 'redis://***@valkey:6379'];
        yield 'credential query values' => [
            'wss://ha:8123/api/websocket?access_token=t&verbose=1',
            'wss://ha:8123/api/websocket?access_token=***&verbose=1',
        ];
        yield 'short password parameter' => ['redis://valkey:6379/0?pass=hunter2', 'redis://valkey:6379/0?pass=***'];
        yield 'fragment' => ['https://ha/#token', 'https://ha/#***'];
        yield 'nothing to hide' => ['redis://valkey:6379/0?timeout=2', 'redis://valkey:6379/0?timeout=2'];
        yield 'not a url' => ['hunter2', 'hunter2'];
        yield 'socket without a host' => ['unix:///run/valkey.sock?password=hunter2', 'unix:///run/valkey.sock?password=***'];
    }

    public function testOnlySchemeAndAuthorityMakeAUrl(): void
    {
        self::assertTrue(UrlRedactor::looksLikeUrl('redis://valkey'));
        self::assertFalse(UrlRedactor::looksLikeUrl('valkey:6379'));
    }
}
