<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Php;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Php\IdentifierAllocator;

#[CoversClass(IdentifierAllocator::class)]
final class IdentifierAllocatorTest extends TestCase
{
    public function testFirstClaimantKeepsThePlainName(): void
    {
        $allocator = new IdentifierAllocator(['ha'], 'Entity');

        self::assertSame('porch2', $allocator->claim('porch2'));
        self::assertSame('porch22', $allocator->claim('porch2'));
        self::assertSame('porch23', $allocator->claim('porch2'));
    }

    public function testReservedMemberGetsTheSuffix(): void
    {
        $allocator = new IdentifierAllocator(['id', 'state', 'entity', 'ha'], 'Service');

        self::assertSame('stateService', $allocator->claim('state'));
        self::assertSame('stateService2', $allocator->claim('state'));
        self::assertSame('turnOn', $allocator->claim('turnOn'));
    }

    public function testNamesThatDifferOnlyInCaseStillCollide(): void
    {
        $allocator = new IdentifierAllocator(['ha'], 'Service');

        self::assertSame('reload', $allocator->claim('reload'));
        self::assertSame('Reload2', $allocator->claim('Reload'));
    }

    public function testExactClaimTakesNameWithoutNumbering(): void
    {
        $allocator = new IdentifierAllocator(['ha'], 'Entity');

        $allocator->claimExactly('hall');

        self::assertSame('Hall2', $allocator->claim('Hall'));
        self::assertTrue($allocator->isReserved('HA'));
    }

    public function testExactClaimOfTakenNameIsALogicError(): void
    {
        $allocator = new IdentifierAllocator([], 'Entity');
        $allocator->claim('hall');

        $this->expectException(LogicException::class);

        $allocator->claimExactly('HALL');
    }
}
