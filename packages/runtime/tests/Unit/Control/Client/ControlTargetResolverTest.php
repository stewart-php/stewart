<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\ControlAddress;
use Stewart\Runtime\Config\ControlConfig;
use Stewart\Runtime\Control\Client\ControlTargetResolver;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Runtime\Exception\ControlError;
use Stewart\Runtime\Exception\ControlException;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ControlTargetResolver::class)]
#[CoversClass(ControlException::class)]
final class ControlTargetResolverTest extends TestCase
{
    use AssertsReason;

    public function testBothFlagsSkipReadingConfiguration(): void
    {
        $target = new ControlTargetResolver()->resolveTarget('tcp://0.0.0.0:7333', 'secret', static function (): never {
            self::fail('The configuration must not be loaded.');
        });

        self::assertSame('tcp://127.0.0.1:7333', (string) $target->address, 'A wildcard listen address is dialled over loopback.');
        self::assertSame('secret', $target->token);
    }

    public function testDaemonConfigFillsMissingFlags(): void
    {
        $target = new ControlTargetResolver()->resolveTarget(null, null, static fn(): ControlConfig => new ControlConfig(ControlAddress::parse('unix://var/run/stewart.sock'), 'from-env'));

        self::assertSame('unix://var/run/stewart.sock', (string) $target->address);
        self::assertSame('from-env', $target->token);

        $overridden = new ControlTargetResolver()->resolveTarget('tcp://[::]:1', null, static fn(): ControlConfig => new ControlConfig(ControlAddress::parse('unix://x.sock'), 'from-env'));
        self::assertSame('tcp://127.0.0.1:1', (string) $overridden->address);
    }

    public function testListenSetToOffIsReported(): void
    {
        $this->assertThrowsReason(ControlError::ControlDisabled, fn() => new ControlTargetResolver()->resolveTarget(null, 'secret', static fn(): ControlConfig => new ControlConfig(null, null)));
    }

    public function testMissingTokenNamesVariableAndFlag(): void
    {
        $e = $this->assertThrowsReason(ControlError::TokenMissing, fn() => new ControlTargetResolver()->resolveTarget(null, null, static fn(): ControlConfig => new ControlConfig(ControlAddress::parse('unix://x.sock'), null)));

        self::assertStringContainsString('set STEWART_CONTROL__TOKEN or pass --token', $e->getMessage());
    }

    public function testUnreadableConfigIsConfigUnavailable(): void
    {
        $failure = ConfigurationException::valueInvalid('x', 'a readable file');

        $e = $this->assertThrowsReason(ControlError::ConfigUnavailable, fn() => new ControlTargetResolver()->resolveTarget(null, 'secret', static fn(): ControlConfig => throw $failure));

        self::assertSame($failure, $e->getPrevious());
    }

    public function testConfigUnavailableNamesItsCause(): void
    {
        $failure = ConfigurationException::valueInvalid('x', 'a readable file');

        $e = $this->assertThrowsReason(ControlError::ConfigUnavailable, fn() => new ControlTargetResolver()->resolveTarget(null, null, static fn(): ControlConfig => throw $failure));

        self::assertStringEndsWith(': ' . $failure->getMessage(), $e->getMessage());
    }
}
