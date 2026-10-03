<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit\Connection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Connection\HomeAssistantUrl;
use Stewart\Client\Exception\HaClientError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(HomeAssistantUrl::class)]
final class HomeAssistantUrlTest extends TestCase
{
    use AssertsReason;

    #[DataProvider('provideAddresses')]
    public function testAddressBecomesWebsocketUrl(string $given, string $expected): void
    {
        self::assertSame($expected, HomeAssistantUrl::parse($given)->reveal());
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideAddresses(): iterable
    {
        $endpoint = 'ws://ha.local:8123/api/websocket';

        yield 'already correct' => [$endpoint, $endpoint];
        yield 'bare host and port' => ['ha.local:8123', $endpoint];
        yield 'http becomes ws' => ['http://ha.local:8123', $endpoint];
        yield 'trailing slash' => ['http://ha.local:8123/', $endpoint];
        yield 'surrounding whitespace' => ['  http://ha.local:8123  ', $endpoint];
        yield 'upper-case scheme' => ['HTTP://ha.local:8123', $endpoint];
        yield 'https becomes wss' => ['https://ha.example.com', 'wss://ha.example.com/api/websocket'];
        yield 'wss is left alone' => ['wss://ha.example.com/api/websocket', 'wss://ha.example.com/api/websocket'];
        yield 'the supervisor proxy keeps its path' => ['http://supervisor/core/websocket', 'ws://supervisor/core/websocket'];
        yield 'a proxy prefix keeps its path' => ['https://proxy.example.com/homeassistant', 'wss://proxy.example.com/homeassistant/api/websocket'];
        yield 'a query string stays at the end' => ['https://ha.example.com?access_token=t', 'wss://ha.example.com/api/websocket?access_token=t'];

        yield 'a query string on an already correct url' => [
            'wss://ha.example.com/api/websocket?access_token=t',
            'wss://ha.example.com/api/websocket?access_token=t',
        ];
    }

    #[DataProvider('provideUnusableAddresses')]
    public function testForeignSchemeOrMissingHostIsRefused(string $given): void
    {
        self::assertNull(HomeAssistantUrl::tryParse($given));

        $this->assertThrowsReason(HaClientError::UrlInvalid, fn() => HomeAssistantUrl::parse($given));
    }

    /** @return iterable<string, array{string}> */
    public static function provideUnusableAddresses(): iterable
    {
        yield 'a misspelled scheme' => ['htp://ha.local'];
        yield 'a scheme that is not the web' => ['ftp://ha.local'];
        yield 'a scheme with no host' => ['http://'];
        yield 'nothing at all' => ['  '];
    }

    public function testRefusalMasksCredentials(): void
    {
        $e = $this->assertThrowsReason(HaClientError::UrlInvalid, fn() => HomeAssistantUrl::parse('htp://user:pw@ha.local'));

        self::assertStringContainsString('"htp://***:***@ha.local" is not a Home Assistant URL', $e->getMessage());
    }

    public function testStringFormMasksCredentials(): void
    {
        $url = HomeAssistantUrl::parse('wss://user:pw@ha.example.com:8123/api/websocket?access_token=t');

        self::assertSame('wss://***:***@ha.example.com:8123/api/websocket?access_token=***', (string) $url);
        self::assertSame('wss://user:pw@ha.example.com:8123/api/websocket?access_token=t', $url->reveal());
    }
}
