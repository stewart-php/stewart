<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\HttpConfig;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(HttpConfig::class)]
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

    public function testEmptyListenIsRefused(): void
    {
        self::assertThrowsReason(ConfigurationError::KeyParseFailed, static fn() => ConfigFixture::createStewartConfig(['http' => ['listen' => '']]));
    }

    public function testListenAddressIsParsed(): void
    {
        self::assertSame('tcp://0.0.0.0:8080', (string) ConfigFixture::createStewartConfig(['http' => ['listen' => 'tcp://0.0.0.0:8080']])->http->listen);
    }
}
