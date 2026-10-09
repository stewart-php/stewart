<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Registry\Update;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\EntityAlias;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Contracts\Registry\Update\EntityAliasesChange;
use Stewart\Contracts\Registry\Update\EntityLabelsChange;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;

#[CoversClass(EntityRegistryUpdate::class)]
#[CoversClass(EntityLabelsChange::class)]
#[CoversClass(EntityAliasesChange::class)]
final class EntityRegistryUpdateTest extends TestCase
{
    public function testCreatedUpdateIsEmpty(): void
    {
        self::assertTrue(new EntityRegistryUpdate()->isEmpty());
        self::assertFalse(new EntityRegistryUpdate()->withHidden(false)->isEmpty());
        self::assertFalse(new EntityRegistryUpdate()->withoutName()->isEmpty());
    }

    public function testClearingDiffersFromLeavingAsIs(): void
    {
        $current = new RegisteredEntity(new EntityId('light.hall'), areaId: new AreaId('hall'), name: 'Hall', icon: 'mdi:lamp');

        $updated = new EntityRegistryUpdate()->withoutName()->withIcon('mdi:ceiling-light')->applyTo($current);

        self::assertNull($updated->name);
        self::assertSame('mdi:ceiling-light', $updated->icon);
        self::assertSame('hall', $updated->areaId?->value);
        self::assertNull(new EntityRegistryUpdate()->withoutArea()->applyTo($current)->areaId);
    }

    public function testHiddenAndDisabledAreSetByUser(): void
    {
        $current = new RegisteredEntity(new EntityId('light.hall'), hiddenBy: 'integration');

        $updated = new EntityRegistryUpdate()->withHidden(false)->withDisabled(true)->applyTo($current);

        self::assertFalse($updated->isHidden());
        self::assertSame('user', $updated->disabledBy);
    }

    public function testAddedAndRemovedLabelsNeedCurrentEntry(): void
    {
        $current = new RegisteredEntity(new EntityId('light.hall'), labelIds: [new LabelId('night'), new LabelId('hue')]);
        $update = new EntityRegistryUpdate()->withAddedLabels('battery')->withRemovedLabels('hue');

        $resolved = $update->resolveAgainst($current);

        self::assertTrue($update->needsCurrentEntry());
        self::assertFalse($resolved->needsCurrentEntry());
        self::assertEquals([new LabelId('night'), new LabelId('battery')], $resolved->labels?->replacement);
    }

    public function testReplacedLabelsFoldLaterEdits(): void
    {
        $update = new EntityRegistryUpdate()->withLabels('night', 'hue')->withAddedLabels('battery')->withRemovedLabels('night');

        self::assertFalse($update->needsCurrentEntry());
        self::assertEquals([new LabelId('hue'), new LabelId('battery')], $update->labels?->replacement);
    }

    public function testLaterAddCancelsEarlierRemove(): void
    {
        $current = new RegisteredEntity(new EntityId('light.hall'), labelIds: [new LabelId('night')]);

        $updated = new EntityRegistryUpdate()->withRemovedLabels('night')->withAddedLabels('night')->applyTo($current);

        self::assertSame(['night'], $updated->listLabelIds()->toStrings());
    }

    public function testAliasesMergeIntoUnknownAliasesAsEmpty(): void
    {
        $current = new RegisteredEntity(new EntityId('light.hall'));

        $updated = new EntityRegistryUpdate()->withAddedAliases('Hall lamp', EntityAlias::entityName())->applyTo($current);

        self::assertEquals([EntityAlias::named('Hall lamp'), EntityAlias::entityName()], $updated->aliases);
        self::assertNull(new EntityRegistryUpdate()->withHidden(true)->applyTo($current)->aliases);
    }

    public function testRemovedAliasResolvesAgainstCurrentAliases(): void
    {
        $current = new RegisteredEntity(new EntityId('light.hall'), aliases: [EntityAlias::named('Hall lamp'), EntityAlias::entityName()]);

        $resolved = new EntityRegistryUpdate()->withRemovedAliases('Hall lamp')->resolveAgainst($current);

        self::assertEquals([EntityAlias::entityName()], $resolved->aliases?->replacement);
    }
}
