<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\HttpConfig;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;

#[CoversClass(HttpConfig::class)]
final class HttpConfigTest extends TestCase
{
    public function testListenerIsOffByDefault(): void
    {
        self::assertNull(ConfigFixture::createStewartConfig()->http->listen);
    }

    public function testOffInAnyCaseDisablesListener(): void
    {
        self::assertNull(ConfigFixture::createStewartConfig(['http' => ['listen' => 'OFF']])->http->listen);
    }

    public function testListenAddressIsParsed(): void
    {
        self::assertSame('tcp://0.0.0.0:8080', (string) ConfigFixture::createStewartConfig(['http' => ['listen' => 'tcp://0.0.0.0:8080']])->http->listen);
    }
}
