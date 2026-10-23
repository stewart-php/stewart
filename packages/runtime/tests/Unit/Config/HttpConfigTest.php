<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\HttpConfig;
use Stewart\Runtime\Config\HttpMetricsConfig;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(HttpConfig::class)]
#[CoversClass(HttpMetricsConfig::class)]
final class HttpConfigTest extends TestCase
{
    use AssertsReason;

    public function testListenerIsOffByDefault(): void
    {
        self::assertNull(ConfigFixture::createStewartConfig()->http->listen);
    }

    public function testOffInAnyCaseDisablesListener(): void
    {
        self::assertNull(ConfigFixture::createStewartConfig(['http' => ['listen' => 'OFF']])->http->listen);
    }

    public function testSurroundingWhitespaceIsIgnored(): void
    {
        self::assertSame('tcp://0.0.0.0:8080', (string) ConfigFixture::createStewartConfig(['http' => ['listen' => ' tcp://0.0.0.0:8080 ']])->http->listen);
    }

    public function testEmptyListenDisablesListener(): void
    {
        self::assertNull(ConfigFixture::createStewartConfig(['http' => ['listen' => '']])->http->listen);
    }

    public function testListenAddressIsParsed(): void
    {
        self::assertSame('tcp://0.0.0.0:8080', (string) ConfigFixture::createStewartConfig(['http' => ['listen' => 'tcp://0.0.0.0:8080']])->http->listen);
    }

    public function testMetricsPortIsOffByDefault(): void
    {
        $metrics = ConfigFixture::createStewartConfig()->http->metrics;

        self::assertNull($metrics->listen);
        self::assertNull($metrics->token);
    }

    public function testMetricsListenAndTokenAreRead(): void
    {
        $metrics = ConfigFixture::createStewartConfig(['http' => ['metrics' => ['listen' => 'tcp://0.0.0.0:9464', 'token' => 'scrape']]])->http->metrics;

        self::assertSame('tcp://0.0.0.0:9464', (string) $metrics->listen);
        self::assertSame('scrape', $metrics->token);
    }

    public function testEmptyMetricsTokenMeansOpen(): void
    {
        self::assertNull(ConfigFixture::createStewartConfig(['http' => ['metrics' => ['listen' => 'tcp://0.0.0.0:9464', 'token' => '']]])->http->metrics->token);
    }

    public function testListenersOnTheirOwnPortsAreValid(): void
    {
        $this->expectNotToPerformAssertions();

        self::createHttpConfig(['listen' => 'tcp://0.0.0.0:8080', 'admin' => ['listen' => 'tcp://127.0.0.1:8081', 'token' => 'admin'], 'metrics' => ['listen' => 'tcp://0.0.0.0:9464']])
            ->requireValidListeners();
    }

    public function testMetricsPortNeedsNoToken(): void
    {
        $this->expectNotToPerformAssertions();

        self::createHttpConfig(['metrics' => ['listen' => 'tcp://0.0.0.0:9464']])->requireValidListeners();
    }

    public function testAdminListenWithoutTokenIsRefused(): void
    {
        $http = self::createHttpConfig(['admin' => ['listen' => 'tcp://127.0.0.1:8081']]);

        self::assertThrowsReason(ConfigurationError::AdminTokenMissing, $http->requireValidListeners(...));
    }

    public function testTwoListenersOnOneAddressAreRefused(): void
    {
        $http = self::createHttpConfig(['admin' => ['listen' => 'tcp://127.0.0.1:8081', 'token' => 'admin'], 'metrics' => ['listen' => 'tcp://127.0.0.1:8081']]);

        self::assertThrowsReason(ConfigurationError::HttpListenAddressShared, $http->requireValidListeners(...));
    }

    public function testWildcardSharesThePortWithEveryAddress(): void
    {
        $http = self::createHttpConfig(['listen' => 'tcp://0.0.0.0:8080', 'metrics' => ['listen' => 'tcp://127.0.0.1:8080']]);

        self::assertThrowsReason(ConfigurationError::HttpListenAddressShared, $http->requireValidListeners(...));
    }

    /** @param array<string, mixed> $http */
    private static function createHttpConfig(array $http): HttpConfig
    {
        return ConfigFixture::createStewartConfig(['http' => $http])->http;
    }
}
