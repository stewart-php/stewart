<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ExposeConfig::class)]
final class ExposeConfigTest extends TestCase
{
    use AssertsReason;

    public function testInstanceIsDefaultWhenUnset(): void
    {
        self::assertSame('default', ConfigFixture::createStewartConfig()->expose->instance->value);
    }

    public function testInstanceIsRead(): void
    {
        self::assertSame('upstairs', ConfigFixture::createStewartConfig(['expose' => ['instance' => 'upstairs']])->expose->instance->value);
    }

    public function testInstanceWithDashIsRefused(): void
    {
        self::assertThrowsReason(ConfigurationError::KeyParseFailed, static fn() => ConfigFixture::createStewartConfig(['expose' => ['instance' => 'up-stairs']]));
    }

    public function testPruneIsOnByDefault(): void
    {
        self::assertTrue(ConfigFixture::createStewartConfig()->expose->prune);
    }

    public function testPruneCanBeTurnedOff(): void
    {
        self::assertFalse(ConfigFixture::createStewartConfig(['expose' => ['prune' => false]])->expose->prune);
    }
}
