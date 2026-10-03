<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\IdentifierError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(EntityId::class)]
final class EntityIdTest extends TestCase
{
    use AssertsReason;

    public function testSplitsIntoDomainAndObjectId(): void
    {
        $id = new EntityId('binary_sensor.hall_motion');

        self::assertSame('binary_sensor', $id->domain);
        self::assertSame('hall_motion', $id->objectId);
        self::assertSame('binary_sensor.hall_motion', $id->value);
    }

    public function testNumericDomain(): void
    {
        $id = new EntityId('0.foo');

        self::assertSame('0', $id->domain);
        self::assertSame('foo', $id->objectId);
    }

    public function testComparesByValue(): void
    {
        $id = new EntityId('light.hall');

        self::assertTrue($id->equals(new EntityId('light.hall')));
        self::assertFalse($id->equals(new EntityId('light.porch')));
        self::assertSame($id, EntityId::fromStringOrId($id));
        self::assertEquals($id, EntityId::fromStringOrId('light.hall'));
    }

    #[DataProvider('provideMalformedEntityIds')]
    public function testRejectsMalformedIds(string $raw): void
    {
        self::assertNull(EntityId::tryFromString($raw));

        $this->assertThrowsReason(IdentifierError::EntityIdInvalid, fn() => new EntityId($raw));
    }

    /** @return iterable<string, array{string}> */
    public static function provideMalformedEntityIds(): iterable
    {
        yield 'empty' => [''];
        yield 'no domain' => ['hall'];
        yield 'upper case' => ['Light.hall'];
        yield 'two dots' => ['light.hall.x'];
        yield 'glob' => ['light.*'];
        yield 'trailing newline' => ["light.hall\n"];
    }
}
