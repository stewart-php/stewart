<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\HttpListenAddress;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(HttpListenAddress::class)]
final class HttpListenAddressTest extends TestCase
{
    use AssertsReason;

    public function testTcpAddressSplitsIntoHostAndPort(): void
    {
        $address = HttpListenAddress::parse('tcp://0.0.0.0:8080');

        self::assertSame('0.0.0.0', $address->ip);
        self::assertSame(8080, $address->port);
        self::assertSame('tcp://0.0.0.0:8080', (string) $address);
    }

    public function testBracketedIpv6KeepsBareIp(): void
    {
        $address = HttpListenAddress::parse('tcp://[::]:8080');

        self::assertSame('::', $address->ip);
        self::assertSame('tcp://[::]:8080', (string) $address);
    }

    public function testHostNameIsRefused(): void
    {
        self::assertThrowsReason(ConfigurationError::ValueInvalid, static fn() => HttpListenAddress::parse('tcp://localhost:8080'));
    }

    public function testUnixSocketIsRefused(): void
    {
        self::assertThrowsReason(ConfigurationError::ValueInvalid, static fn() => HttpListenAddress::parse('unix://var/run/http.sock'));
    }

    public function testPortOutOfRangeIsRefused(): void
    {
        self::assertThrowsReason(ConfigurationError::ValueInvalid, static fn() => HttpListenAddress::parse('tcp://0.0.0.0:70000'));
    }
}
