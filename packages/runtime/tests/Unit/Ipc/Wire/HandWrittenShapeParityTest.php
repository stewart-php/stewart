<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc\Wire;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Ipc\Wire\EntityStateConverter;
use Stewart\Runtime\Ipc\Wire\EventContextConverter;
use Stewart\Runtime\Json\ClassShapeReader;
use Stewart\Runtime\Json\Collection\ValueConverterCollection;
use Stewart\Runtime\Json\EpochInstantConverter;
use Stewart\Runtime\Json\StringIdentifierConverter;
use Stewart\Runtime\Json\ValueConverter;
use Stewart\Runtime\Json\WireMapper;
use Stewart\Support\Json\JsonDecoder;
use Stewart\Support\Json\JsonEncoder;
use Stewart\Support\Json\JsonShape;

#[CoversClass(EventContextConverter::class)]
#[CoversClass(EntityStateConverter::class)]
// A constructor parameter the converter does not write shows up as a key the reflected shape has.
final class HandWrittenShapeParityTest extends TestCase
{
    #[DataProvider('provideSamples')]
    public function testConverterWritesWhatTheReflectedShapeWould(ValueConverter $converter, object $sample): void
    {
        $reflected = new WireMapper(new ClassShapeReader(ValueConverterCollection::keyedByHandledClass([
            new EpochInstantConverter(),
            StringIdentifierConverter::createForEntityIds(),
        ])));

        self::assertSame($reflected->encodeObject($sample), JsonEncoder::encodeToJson($converter->encodeValue($sample)));
    }

    #[DataProvider('provideSamples')]
    public function testConverterReadsBackWhatItWrote(ValueConverter $converter, object $sample): void
    {
        $encoded = JsonDecoder::decodeJson(JsonEncoder::encodeToJson($converter->encodeValue($sample)));

        self::assertEquals($sample, $converter->decodeValue($encoded, 'sample'));
    }

    /** @return iterable<string, array{ValueConverter, object}> */
    public static function provideSamples(): iterable
    {
        $at = Instant::fromEpochMicroseconds(1_758_700_000_123_456);
        $contexts = new EventContextConverter();
        $states = new EntityStateConverter(StringIdentifierConverter::createForEntityIds(), new EpochInstantConverter(), $contexts);
        $attributes = ['brightness' => 254, 'rgb_color' => [255, 170, 80], 'gain' => 1.0, 'effect' => null];

        yield 'full context' => [$contexts, new EventContext('ctx', 'parent', 'user')];
        yield 'bare context' => [$contexts, new EventContext('ctx')];
        yield 'full state' => [$states, new EntityState(new EntityId('light.hall'), 'on', $attributes, $at, $at->plus(Duration::seconds(1)), new EventContext('ctx', 'parent', 'user'))];
        yield 'bare state' => [$states, new EntityState(new EntityId('sensor.x'), '1')];
        yield 'list-shaped attributes' => [$states, new EntityState(new EntityId('sensor.x'), '1', JsonShape::treatKeysAsStrings(['a', 'b']))];
    }
}
