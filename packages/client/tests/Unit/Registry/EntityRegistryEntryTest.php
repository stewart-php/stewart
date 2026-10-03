<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Registry\EntityRegistryEntry;

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

    public function testEmptyStringIsNoValue(): void
    {
        $entry = EntityRegistryEntry::fromArray(['entity_id' => 'light.hall', 'hidden_by' => '', 'name' => '']);

        self::assertFalse($entry->isHidden());
        self::assertNull($entry->name);
    }
}
