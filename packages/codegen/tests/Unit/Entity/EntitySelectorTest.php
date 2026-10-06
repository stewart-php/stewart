<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Entity\EntityInclusionRules;
use Stewart\Codegen\Entity\EntitySelection;
use Stewart\Codegen\Entity\EntitySelector;
use Stewart\Codegen\Tests\Fixtures\GoldenSnapshot;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Selector\Selector;

#[CoversClass(EntitySelector::class)]
#[CoversClass(EntitySelection::class)]
#[CoversClass(EntityInclusionRules::class)]
final class EntitySelectorTest extends TestCase
{
    public function testDisabledHiddenAndExcludedAreIgnored(): void
    {
        $selection = new EntitySelector()->selectEntities(GoldenSnapshot::loadSnapshot(), EntityInclusionRules::fromPatterns(['*'], ['light.debug_*']));

        self::assertSame(['light.attic', 'light.cellar', 'light.debug_strip'], $selection->ignored->toStrings());
        self::assertNotContains('light.attic', $selection->listEntityIds()->toStrings());
        self::assertContains('light.hall', $selection->listEntityIds()->toStrings());
    }

    public function testIncludeNarrowsSelection(): void
    {
        $selection = new EntitySelector()->selectEntities(GoldenSnapshot::loadSnapshot(), EntityInclusionRules::fromPatterns(['light.*', 'switch.pump']));

        self::assertSame(
            ['light.1st_floor', 'light.debug_strip', 'light.hall', 'light.porch2', 'light.porch_2', 'switch.pump'],
            $selection->listEntityIds()->toStrings(),
        );
    }

    public function testExcludeWinsOverInclude(): void
    {
        $rules = EntityInclusionRules::fromPatterns(['light.*'], ['light.hall']);

        self::assertTrue($rules->allows(new EntityId('light.porch2')));
        self::assertFalse($rules->allows(new EntityId('light.hall')));
        self::assertFalse($rules->allows(new EntityId('switch.pump')));
    }

    public function testIncludeMatchingNoEntityIsUnmatched(): void
    {
        $unmatched = EntityInclusionRules::fromPatterns(['light.*', 'cover.*', 'light.attic', 'switch.pump'])->listUnmatchedIncludes(GoldenSnapshot::loadSnapshot()->states);

        self::assertSame(['cover.*'], $unmatched->mapToList(static fn(Selector $include): string => $include->getPattern()));
    }

    public function testDefaultRulesAllowEverything(): void
    {
        self::assertTrue(EntityInclusionRules::allowingEverything()->allows(new EntityId('anything.at_all')));
    }

    public function testGroupsByDomainInIdOrder(): void
    {
        $byDomain = new EntitySelector()->selectEntities(GoldenSnapshot::loadSnapshot(), EntityInclusionRules::fromPatterns(['light.*']))->generated->groupByDomain();

        self::assertSame(['light'], array_keys($byDomain));
        self::assertSame('light.1st_floor', $byDomain['light']->getFirst()?->entityId->value);
    }
}
