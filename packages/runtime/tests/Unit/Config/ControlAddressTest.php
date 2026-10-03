<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\ControlAddress;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Config\TcpControlAddress;
use Stewart\Runtime\Config\UnixControlAddress;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ControlAddress::class)]
#[CoversClass(UnixControlAddress::class)]
#[CoversClass(TcpControlAddress::class)]
final class ControlAddressTest extends TestCase
{
    use AssertsReason;

    public function testUnixPathIsKeptAsWritten(): void
    {
        $address = ControlAddress::parse('unix://var/run/stewart.sock');

        self::assertInstanceOf(UnixControlAddress::class, $address);
        self::assertSame('var/run/stewart.sock', $address->path);
        self::assertSame('/srv/stewart/var/run/stewart.sock', $address->resolveSocketPath(new ProjectRoot('/srv/stewart')));
        self::assertTrue($address->isLoopback());
        self::assertSame('unix://var/run/stewart.sock', (string) $address);
    }

    public function testTcpAddressSplitsIntoHostAndPort(): void
    {
        $address = ControlAddress::parse('tcp://0.0.0.0:7333');

        self::assertInstanceOf(TcpControlAddress::class, $address);
        self::assertSame('0.0.0.0', $address->host);
        self::assertSame(7333, $address->port);
        self::assertFalse($address->isLoopback());
        self::assertSame('tcp://0.0.0.0:7333', (string) $address);
    }

    public function testLoopbackIsRecognizedByName(): void
    {
        self::assertTrue(ControlAddress::parse('tcp://127.0.0.1:7333')->isLoopback());
        self::assertTrue(ControlAddress::parse('tcp://localhost:7333')->isLoopback());
        self::assertTrue(ControlAddress::parse('tcp://[::1]:7333')->isLoopback());
        self::assertFalse(ControlAddress::parse('tcp://homeassistant.local:7333')->isLoopback());
    }

    public function testWildcardListenAddressConnectsOverLoopback(): void
    {
        $connectable = ControlAddress::parse('tcp://0.0.0.0:7333')->toConnectableAddress();

        self::assertSame('tcp://127.0.0.1:7333', (string) $connectable);
        self::assertSame('tcp://[::]:7333', (string) ControlAddress::parse('tcp://[::]:7333'));
        self::assertSame('tcp://127.0.0.1:7333', (string) ControlAddress::parse('tcp://[::]:7333')->toConnectableAddress());
        self::assertSame('unix://var/run/stewart.sock', (string) ControlAddress::parse('unix://var/run/stewart.sock')->toConnectableAddress());
    }

    public function testAnythingElseIsRefusedWithTheTwoShapesItAccepts(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::ValueInvalid, fn() => ControlAddress::parse('http://localhost:8080'));

        self::assertStringContainsString('"http://localhost:8080" is not a control address; use unix://path, tcp://host:port or "off"', $e->getMessage());
    }

    public function testPortOutsideTheRangeIsRefused(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::ValueInvalid, fn() => ControlAddress::parse('tcp://127.0.0.1:70000'));

        self::assertStringContainsString('"tcp://127.0.0.1:70000" is not a TCP control address with a port in 1-65535', $e->getMessage());
    }

    public function testResolvedPathOverLimitIsRefused(): void
    {
        $address = ControlAddress::parse('unix://' . str_repeat('x', 90));
        \assert($address instanceof UnixControlAddress);

        $e = $this->assertThrowsReason(ConfigurationError::ValueInvalid, fn() => $address->resolveSocketPath(new ProjectRoot('/project/dir')));

        self::assertStringContainsString('is 103 bytes, the limit is 100', $e->getMessage());
    }

    public function testTcpUsesUriAndUnixUsesFullPath(): void
    {
        $projectRoot = new ProjectRoot('/srv/stewart');

        self::assertSame('tcp://localhost:7333', ControlAddress::parse('tcp://localhost:7333')->resolveSocketAddress($projectRoot));
        self::assertSame('/run/stewart.sock', (string) ControlAddress::parse('unix:///run/stewart.sock')->resolveSocketAddress($projectRoot));
    }

    public function testConstructorsCheckWhatParseDoes(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::ValueInvalid, fn() => new TcpControlAddress('', 7333));

        self::assertStringContainsString('is not a TCP control address with a host and a port', $e->getMessage());
    }
}
