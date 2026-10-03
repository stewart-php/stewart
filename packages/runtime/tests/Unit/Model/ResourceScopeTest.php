<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Model\ResourceScope;

#[CoversClass(ResourceScope::class)]
final class ResourceScopeTest extends TestCase
{
    public function testSharedScopeHasNoApp(): void
    {
        $shared = ResourceScope::shared();

        self::assertTrue($shared->isShared());
        self::assertNull($shared->appId);
        self::assertSame('@shared', $shared->wireValue());
        self::assertSame('@shared', (string) $shared);
    }

    public function testRoundTripsThroughWireValue(): void
    {
        $app = ResourceScope::forApp(new AppId('demo'));

        self::assertSame('demo', $app->wireValue());
        self::assertTrue($app->equals(ResourceScope::tryFromWireValue('demo') ?? ResourceScope::shared()));
        self::assertTrue(ResourceScope::shared()->equals(ResourceScope::tryFromWireValue('@shared') ?? $app));
        self::assertFalse($app->equals(ResourceScope::shared()));
        self::assertNull(ResourceScope::tryFromWireValue('Not An Id'));
    }
}
