<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;

#[CoversClass(EntityState::class)]
#[CoversClass(StateChange::class)]
final class EntityStateTest extends TestCase
{
    public function testFriendlyNameFallsBackToEntityId(): void
    {
        self::assertSame('light.hall', new EntityState(new EntityId('light.hall'), 'on')->getFriendlyName());
    }

    public function testTreatsUnavailableAndUnknownAsUnusable(): void
    {
        self::assertTrue(new EntityState(new EntityId('sensor.x'), 'unavailable')->isUnavailable());
        self::assertTrue(new EntityState(new EntityId('sensor.x'), 'unknown')->isUnavailable());
        self::assertFalse(new EntityState(new EntityId('sensor.x'), 'off')->isUnavailable());
    }

    public function testNumericHelpersRejectNonNumbers(): void
    {
        self::assertSame(21.5, new EntityState(new EntityId('sensor.temp'), '21.5')->getStateAsFloat());
        self::assertNull(new EntityState(new EntityId('sensor.temp'), 'unavailable')->getStateAsFloat());
        self::assertSame(80.0, new EntityState(new EntityId('s.x'), 'on', ['battery' => '80'])->getFloatAttribute('battery'));
        self::assertNull(new EntityState(new EntityId('s.x'), 'on', ['battery' => 'full'])->getFloatAttribute('battery'));
    }

    public function testTypedReadersRejectWrongShape(): void
    {
        $state = new EntityState(new EntityId('light.hall'), 'on', [
            'brightness' => 180,
            'color_temp' => '370',
            'friendly_name' => 'Hall',
            'is_volume_muted' => false,
            'rgb_color' => [255, 128, 0],
        ]);

        self::assertSame(180, $state->getIntAttribute('brightness'));
        self::assertSame(370, $state->getIntAttribute('color_temp'));
        self::assertNull($state->getIntAttribute('friendly_name'));

        self::assertSame('Hall', $state->getStringAttribute('friendly_name'));
        self::assertNull($state->getStringAttribute('brightness'));

        self::assertFalse($state->getBoolAttribute('is_volume_muted'));
        self::assertNull($state->getBoolAttribute('brightness'));

        self::assertSame([255, 128, 0], $state->getArrayAttribute('rgb_color'));
        self::assertNull($state->getArrayAttribute('brightness'));
        self::assertNull($state->getArrayAttribute('absent'));
    }

    public function testStateChangeSeparatesAttributeOnlyUpdates(): void
    {
        $attributeOnly = new StateChange(
            new EntityId('light.hall'),
            new EntityState(new EntityId('light.hall'), 'on', ['brightness' => 100]),
            new EntityState(new EntityId('light.hall'), 'on', ['brightness' => 200]),
        );

        self::assertFalse($attributeOnly->hasStateChanged());

        $realChange = new StateChange(
            new EntityId('light.hall'),
            new EntityState(new EntityId('light.hall'), 'off'),
            new EntityState(new EntityId('light.hall'), 'on'),
        );

        self::assertTrue($realChange->hasStateChanged());
        self::assertTrue($realChange->changedTo('on'));
        self::assertTrue($realChange->changedFrom('off'));
        self::assertFalse($realChange->changedTo('off'));
    }

    public function testStateChangeHandlesAddAndRemove(): void
    {
        $appeared = new StateChange(new EntityId('light.new'), null, new EntityState(new EntityId('light.new'), 'on'));
        self::assertTrue($appeared->isNew());
        self::assertFalse($appeared->isRemoved());

        $removed = new StateChange(new EntityId('light.gone'), new EntityState(new EntityId('light.gone'), 'on'), null);
        self::assertTrue($removed->isRemoved());
        self::assertFalse($removed->isNew());
    }
}
