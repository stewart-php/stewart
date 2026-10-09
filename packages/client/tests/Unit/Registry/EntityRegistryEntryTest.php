<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Registry\EntityRegistryEntry;
use Stewart\Contracts\Registry\EntityAlias;

#[CoversClass(EntityRegistryEntry::class)]
final class EntityRegistryEntryTest extends TestCase
{
    public function testRoundTripsRegistryShape(): void
    {
        $raw = [
            'entity_id' => 'light.hall',
            'disabled_by' => 'user',
            'hidden_by' => null,
            'name' => 'Hall',
            'platform' => 'hue',
        ];

        $entry = EntityRegistryEntry::fromArray($raw);

        self::assertSame('light.hall', (string) $entry->entityId);
        self::assertTrue($entry->isDisabled());
        self::assertFalse($entry->isHidden());
        self::assertSame('Hall', $entry->name);
        self::assertSame($entry->toArray(), EntityRegistryEntry::fromArray($entry->toArray())->toArray());
    }

    public function testCarriesAreaDeviceAndLabels(): void
    {
        $entry = EntityRegistryEntry::fromArray([
            'entity_id' => 'sensor.bulb_signal',
            'area_id' => 'kitchen',
            'device_id' => 'bulb',
            'labels' => ['night', ''],
            'entity_category' => 'diagnostic',
        ]);
        $entity = $entry->toRegisteredEntity();

        self::assertSame($entry->toArray(), EntityRegistryEntry::fromArray($entry->toArray())->toArray());
        self::assertSame('kitchen', $entity->areaId?->value);
        self::assertSame('bulb', $entity->deviceId?->value);
        self::assertSame(['night'], $entity->listLabelIds()->toStrings());
        self::assertTrue($entity->hasEntityCategory());
    }

    public function testOlderSnapshotRowLeavesPlacementEmpty(): void
    {
        $entity = EntityRegistryEntry::fromArray(['entity_id' => 'light.hall', 'disabled_by' => null, 'hidden_by' => null, 'name' => null])->toRegisteredEntity();

        self::assertNull($entity->areaId);
        self::assertNull($entity->deviceId);
        self::assertSame([], $entity->labelIds);
    }

    public function testCarriesIconAndAliases(): void
    {
        $entry = EntityRegistryEntry::fromArray([
            'entity_id' => 'light.hall',
            'icon' => 'mdi:lamp',
            'aliases' => ['Hall lamp', null, '', 7],
        ]);
        $entity = $entry->toRegisteredEntity();

        self::assertSame($entry->toArray(), EntityRegistryEntry::fromArray($entry->toArray())->toArray());
        self::assertSame('mdi:lamp', $entity->icon);
        self::assertEquals([EntityAlias::named('Hall lamp'), EntityAlias::entityName()], $entity->aliases);
    }

    public function testListRowWithoutAliasesLeavesThemUnknown(): void
    {
        self::assertNull(EntityRegistryEntry::fromArray(['entity_id' => 'light.hall'])->toRegisteredEntity()->aliases);
    }

    public function testEmptyStringIsNoValue(): void
    {
        $entry = EntityRegistryEntry::fromArray(['entity_id' => 'light.hall', 'hidden_by' => '', 'name' => '']);

        self::assertFalse($entry->isHidden());
        self::assertNull($entry->name);
    }
}
