<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Attribute\AttributeKind;
use Stewart\Codegen\Attribute\AttributeKindInference;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;

#[CoversClass(AttributeKindInference::class)]
#[CoversClass(AttributeKind::class)]
final class AttributeKindInferenceTest extends TestCase
{
    public function testInfersKindPerValue(): void
    {
        $types = new AttributeKindInference()->inferKindsByAttributeName(EntityStateCollection::keyedByEntityId([
            new EntityState(new EntityId('light.hall'), 'on', [
                'brightness' => 200,
                'friendly_name' => 'Hall',
                'is_hue_group' => false,
                'supported_color_modes' => ['color_temp'],
                'temperature' => 21.5,
            ]),
        ]));

        self::assertSame(['brightness', 'friendly_name', 'is_hue_group', 'supported_color_modes', 'temperature'], array_keys($types));
        self::assertSame(AttributeKind::Float, $types['brightness']);
        self::assertSame(AttributeKind::String, $types['friendly_name']);
        self::assertSame(AttributeKind::Bool, $types['is_hue_group']);
        self::assertSame(AttributeKind::Array, $types['supported_color_modes']);
        self::assertSame(AttributeKind::Float, $types['temperature']);
    }

    public function testNumbersAreFloatAndStringGivesUp(): void
    {
        $types = new AttributeKindInference()->inferKindsByAttributeName(EntityStateCollection::keyedByEntityId([
            new EntityState(new EntityId('sensor.a'), '1', ['battery' => 80, 'level' => 3]),
            new EntityState(new EntityId('sensor.b'), '2', ['battery' => 79.5, 'level' => 'high']),
        ]));

        self::assertSame(AttributeKind::Float, $types['battery']);
        self::assertSame(AttributeKind::Mixed, $types['level']);
    }

    public function testUnavailableEntityContributesNothing(): void
    {
        $types = new AttributeKindInference()->inferKindsByAttributeName(EntityStateCollection::keyedByEntityId([
            new EntityState(new EntityId('sensor.a'), '1', ['battery' => 80]),
            new EntityState(new EntityId('sensor.b'), 'unavailable', ['battery' => 'nonsense', 'restored' => true]),
        ]));

        self::assertSame(['battery'], array_keys($types));
        self::assertSame(AttributeKind::Float, $types['battery']);
    }

    public function testOnlyNullIsMixed(): void
    {
        $types = new AttributeKindInference()->inferKindsByAttributeName(EntityStateCollection::keyedByEntityId([new EntityState(new EntityId('sensor.a'), '1', ['calibrated_at' => null])]));

        self::assertSame(AttributeKind::Mixed, $types['calibrated_at']);
    }

    public function testNullKeepsKnownType(): void
    {
        $types = new AttributeKindInference()->inferKindsByAttributeName(EntityStateCollection::keyedByEntityId([
            new EntityState(new EntityId('sensor.a'), '1', ['battery' => 80]),
            new EntityState(new EntityId('sensor.b'), '2', ['battery' => null]),
        ]));

        self::assertSame(AttributeKind::Float, $types['battery']);
    }
}
