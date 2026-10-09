<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Component\ComponentProtocol;
use Stewart\Client\Component\ComponentVersion;
use Stewart\Client\Exception\HaClientError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ComponentVersion::class)]
final class ComponentVersionTest extends TestCase
{
    use AssertsReason;

    public function testVersionResultIsRead(): void
    {
        $version = ComponentVersion::fromVersionResult(['component_version' => '0.9.0', 'protocol' => 1]);

        self::assertSame('0.9.0', $version->componentVersion);
        self::assertSame(1, $version->protocol);
    }

    public function testMalformedResultIsProtocolViolation(): void
    {
        $this->assertThrowsReason(HaClientError::ProtocolViolation, static fn() => ComponentVersion::fromVersionResult(['protocol' => '1']));
    }

    public function testOnlyOwnProtocolIsCompatible(): void
    {
        self::assertTrue(new ComponentVersion('0.9.0', ComponentProtocol::VERSION)->isCompatible());
        self::assertFalse(new ComponentVersion('0.10.0', ComponentProtocol::VERSION + 1)->isCompatible());
    }
}
