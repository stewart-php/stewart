<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\HttpAdminConfig;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(HttpAdminConfig::class)]
final class HttpAdminConfigTest extends TestCase
{
    use AssertsReason;

    public function testAdminApiIsOffByDefault(): void
    {
        $admin = ConfigFixture::createStewartConfig()->http->admin;

        self::assertNull($admin->listen);
        self::assertNull($admin->token);
    }

    public function testOffInAnyCaseDisablesAdminApi(): void
    {
        self::assertNull(ConfigFixture::createStewartConfig(['http' => ['admin' => ['listen' => 'Off']]])->http->admin->listen);
    }

    public function testListenAndTokenAreRead(): void
    {
        $admin = ConfigFixture::createStewartConfig(['http' => ['admin' => ['listen' => 'tcp://127.0.0.1:8081', 'token' => 'secret']]])->http->admin;

        self::assertSame('tcp://127.0.0.1:8081', (string) $admin->listen);
        self::assertSame('secret', $admin->token);
    }

    public function testEmptyTokenMeansUnset(): void
    {
        self::assertNull(ConfigFixture::createStewartConfig(['http' => ['admin' => ['token' => '']]])->http->admin->token);
    }

    public function testInvalidListenIsRefused(): void
    {
        self::assertThrowsReason(ConfigurationError::KeyParseFailed, static fn() => ConfigFixture::createStewartConfig(['http' => ['admin' => ['listen' => 'unix://admin.sock']]]));
    }
}
