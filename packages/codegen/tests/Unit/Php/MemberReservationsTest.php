<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Php;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Emitter\Service\ServiceMethodEmitter;
use Stewart\Codegen\Php\MemberReservations;
use Stewart\Codegen\Php\MemberScope;
use Stewart\Codegen\Tests\Fixtures\Php\FixedMemberReserver;

#[CoversClass(MemberReservations::class)]
#[CoversClass(MemberScope::class)]
final class MemberReservationsTest extends TestCase
{
    public function testMergesNamesOfEveryReserverInScope(): void
    {
        $allocator = new MemberReservations([
            new FixedMemberReserver(MemberScope::EntityHandle, ['state']),
            new FixedMemberReserver(MemberScope::EntityHandle, ['entity', 'state']),
        ])->createAllocatorFor(MemberScope::EntityHandle);

        self::assertSame('stateService', $allocator->claim('state'));
        self::assertSame('entityService', $allocator->claim('entity'));
    }

    public function testIgnoresNamesReservedInOtherScopes(): void
    {
        $allocator = new MemberReservations([new FixedMemberReserver(MemberScope::StateView, ['value'])])
            ->createAllocatorFor(MemberScope::Parameters);

        self::assertSame('value', $allocator->claim('value'));
    }

    public function testScopeDecidesCollisionSuffix(): void
    {
        $allocator = new MemberReservations([new FixedMemberReserver(MemberScope::StateView, ['raw'])])
            ->createAllocatorFor(MemberScope::StateView);

        self::assertSame('rawAttribute', $allocator->claim('raw'));
    }

    public function testTargetIsReservedOnlyWhenTargeted(): void
    {
        $reservations = new MemberReservations([new ServiceMethodEmitter()]);

        self::assertSame('target', $reservations->createAllocatorFor(MemberScope::Parameters)->claim('target'));
        self::assertSame('targetField', $reservations->createAllocatorFor(MemberScope::TargetedParameters)->claim('target'));
        self::assertSame('thisField', $reservations->createAllocatorFor(MemberScope::Parameters)->claim('this'));
    }
}
