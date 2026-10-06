<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\IdentifierError;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\DeviceId;
use Stewart\Contracts\Registry\FloorId;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\RegistryId;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(RegistryId::class)]
#[CoversClass(AreaId::class)]
#[CoversClass(FloorId::class)]
#[CoversClass(LabelId::class)]
#[CoversClass(DeviceId::class)]
final class RegistryIdTest extends TestCase
{
    use AssertsReason;

    public function testRejectsEmptyId(): void
    {
        self::assertNull(FloorId::tryFromString(''));

        $this->assertThrowsReason(IdentifierError::RegistryIdEmpty, static fn() => new AreaId(''));
    }

    public function testFromStringOrIdKeepsTypedId(): void
    {
        $kitchen = new AreaId('kitchen');

        self::assertSame($kitchen, AreaId::fromStringOrId($kitchen));
        self::assertTrue(AreaId::fromStringOrId('kitchen')->equals($kitchen));
        self::assertSame('kitchen', (string) $kitchen);
    }

    public function testSameValueOfOtherKindIsNotEqual(): void
    {
        self::assertFalse(new AreaId('night')->equals(new LabelId('night')));
        self::assertTrue(new DeviceId('abc')->equals(new DeviceId('abc')));
    }
}
