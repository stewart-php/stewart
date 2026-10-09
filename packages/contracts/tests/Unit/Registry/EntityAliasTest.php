<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Registry\EntityAlias;

#[CoversClass(EntityAlias::class)]
final class EntityAliasTest extends TestCase
{
    public function testEntityNameAliasHasNoPhrase(): void
    {
        self::assertTrue(EntityAlias::entityName()->isEntityName());
        self::assertFalse(EntityAlias::named('Hall lamp')->isEntityName());
    }

    public function testAliasesWithSamePhraseAreEqual(): void
    {
        self::assertTrue(EntityAlias::named('Hall lamp')->equals(new EntityAlias('Hall lamp')));
        self::assertFalse(EntityAlias::named('Hall lamp')->equals(EntityAlias::entityName()));
    }
}
