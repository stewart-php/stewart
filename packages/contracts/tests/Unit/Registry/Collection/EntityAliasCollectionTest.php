<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Registry\Collection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Registry\Collection\EntityAliasCollection;
use Stewart\Contracts\Registry\EntityAlias;

#[CoversClass(EntityAliasCollection::class)]
final class EntityAliasCollectionTest extends TestCase
{
    public function testAddsOnlyMissingAliases(): void
    {
        $aliases = EntityAliasCollection::fromAliases([EntityAlias::named('Lamp')]);

        $merged = $aliases->withAddedMembers(EntityAliasCollection::fromAliases([
            EntityAlias::named('Lamp'),
            EntityAlias::entityName(),
            EntityAlias::entityName(),
        ]));

        self::assertEquals([EntityAlias::named('Lamp'), EntityAlias::entityName()], $merged->listValues());
    }

    public function testRemovesEqualAliases(): void
    {
        $aliases = EntityAliasCollection::fromAliases([EntityAlias::named('Lamp'), EntityAlias::entityName()]);

        $remaining = $aliases->withoutMembers(EntityAliasCollection::fromAliases([new EntityAlias(null)]));

        self::assertEquals([EntityAlias::named('Lamp')], $remaining->listValues());
        self::assertFalse($remaining->contains(EntityAlias::entityName()));
    }
}
