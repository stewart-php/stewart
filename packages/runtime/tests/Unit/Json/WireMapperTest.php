<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Json;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\JsonShapeError;
use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Json\ClassShapeReader;
use Stewart\Runtime\Json\Collection\ValueConverterCollection;
use Stewart\Runtime\Json\DurationConverter;
use Stewart\Runtime\Json\EpochInstantConverter;
use Stewart\Runtime\Json\FieldShape;
use Stewart\Runtime\Json\IsoInstantConverter;
use Stewart\Runtime\Json\WireMapper;
use Stewart\Runtime\Tests\Fixtures\Wire\Color;
use Stewart\Runtime\Tests\Fixtures\Wire\Everything;
use Stewart\Runtime\Tests\Fixtures\Wire\HiddenConstructor;
use Stewart\Runtime\Tests\Fixtures\Wire\HoldsInterface;
use Stewart\Runtime\Tests\Fixtures\Wire\Label;
use Stewart\Runtime\Tests\Fixtures\Wire\NarrowUnion;
use Stewart\Runtime\Tests\Fixtures\Wire\NestsFragment;
use Stewart\Runtime\Tests\Fixtures\Wire\NotPromoted;
use Stewart\Runtime\Tests\Fixtures\Wire\Numbers;
use Stewart\Runtime\Tests\Fixtures\Wire\Priority;
use Stewart\Runtime\Tests\Fixtures\Wire\Ranked;
use Stewart\Runtime\Tests\Fixtures\Wire\SelfReferencing;
use Stewart\Runtime\Tests\Fixtures\Wire\WithFragment;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(WireMapper::class)]
#[CoversClass(ClassShapeReader::class)]
#[CoversClass(EpochInstantConverter::class)]
#[CoversClass(IsoInstantConverter::class)]
#[CoversClass(DurationConverter::class)]
final class WireMapperTest extends TestCase
{
    use AssertsReason;

    private const int AT = 1_758_700_000_123_456;

    private WireMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new WireMapper(new ClassShapeReader(ValueConverterCollection::keyedByHandledClass([new EpochInstantConverter(), new DurationConverter()])));
    }

    public function testEveryKindSurvivesTheWire(): void
    {
        $sent = self::createEverything();

        self::assertEquals($sent, $this->sendThroughTheWire($sent));
    }

    public function testKeysAreSnakeCaseAndTimeKeysNameTheirUnit(): void
    {
        self::assertSame(
            ['name', 'count', 'ratio', 'enabled', 'note', 'color', 'label', 'labels', 'numbers', 'tags', 'payload', 'seen_at_us', 'lag_us'],
            $this->mapper->shapes->resolveClassShape(Everything::class)->fields->mapToList(static fn(FieldShape $field): string => $field->key),
        );
    }

    public function testInstantCanTravelAsIsoText(): void
    {
        $mapper = new WireMapper(new ClassShapeReader(ValueConverterCollection::keyedByHandledClass([new IsoInstantConverter(), new DurationConverter()])));
        $json = $mapper->encodeObject(self::createEverything());

        self::assertStringContainsString('"seen_at":"2025-09-24T07:46:40.123456Z"', $json);
        self::assertEquals(self::createEverything(), $mapper->decodeObject(Everything::class, self::decodeJsonObject($json)));
    }

    public function testMapIsAlwaysAnObjectAndKeepsNumericKeys(): void
    {
        $json = $this->mapper->encodeObject(self::createEverything(tags: []));
        self::assertStringContainsString('"tags":{}', $json);

        $numeric = $this->sendThroughTheWire(self::createEverything(tags: ['7' => 'a']));
        self::assertSame([7], array_keys($numeric->tags));

        $listShaped = $this->mapper->encodeObject(self::createEverything(tags: ['a', 'b']));
        self::assertStringContainsString('"tags":{"0":"a","1":"b"}', $listShaped);
    }

    public function testWholeNumberIsAcceptedWhereAFloatIsDeclared(): void
    {
        $data = self::decodeJsonObject($this->mapper->encodeObject(self::createEverything()));
        $data['ratio'] = 2;

        self::assertSame(2.0, $this->mapper->decodeObject(Everything::class, $data)->ratio);
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $data = self::decodeJsonObject($this->mapper->encodeObject(self::createEverything()));
        $data['later'] = true;

        self::assertEquals(self::createEverything(), $this->mapper->decodeObject(Everything::class, $data));
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function provideMalformedFields(): iterable
    {
        yield 'missing key' => ['note', null, '"note" is missing; expected a string.'];
        yield 'null where required' => ['name', null, '"name" is null; expected a string.'];
        yield 'wrong scalar' => ['count', 'many', '"count" is string; expected a whole number.'];
        yield 'unknown enum' => ['color', 'blue', '"color" is "blue"; expected one of red, green.'];
        yield 'enum of wrong type' => ['color', 1, '"color" is int; expected one of red, green.'];
        yield 'nested object' => ['label', ['text' => 7], '"label.text" is int; expected a string.'];
        yield 'list item' => ['labels', [['text' => 'a'], ['text' => 1]], '"labels.1.text" is int; expected a string.'];
        yield 'not a list' => ['numbers', ['a' => 1], '"numbers" is array; expected a list.'];
        yield 'scalar list item' => ['numbers', [1, 'two'], '"numbers.1" is string; expected a whole number.'];
        yield 'negative duration' => ['lag_us', -1, '"lag_us" is -1; expected a non-negative whole number of microseconds.'];
    }

    #[DataProvider('provideMalformedFields')]
    public function testMalformedDataNamesItsPath(string $key, mixed $value, string $message): void
    {
        $data = self::decodeJsonObject($this->mapper->encodeObject(self::createEverything()));

        if ($key === 'note') {
            unset($data[$key]);
        } else {
            $data[$key] = $value;
        }

        $this->expectException(JsonShapeException::class);
        $this->expectExceptionMessage($message);

        $this->mapper->decodeObject(Everything::class, $data);
    }

    public function testNumericTextIsWrongTypeForIntEnum(): void
    {
        $this->assertThrowsReason(JsonShapeError::WrongType, fn() => $this->mapper->decodeObject(Ranked::class, ['priority' => '1']));
        self::assertSame(Priority::High, $this->mapper->decodeObject(Ranked::class, ['priority' => 2])->priority);
    }

    public function testFragmentIsSplicedAsItsOwnJson(): void
    {
        $json = $this->mapper->encodeObject(new WithFragment(7, new Numbers([1, 2, 3])));

        self::assertSame('{"id":7,"numbers":[1,2,3]}', $json);
        self::assertEquals(new WithFragment(7, new Numbers([1, 2, 3])), $this->mapper->decodeObject(WithFragment::class, self::decodeJsonObject($json)));
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function provideUnmappableClasses(): iterable
    {
        yield 'not promoted' => [NotPromoted::class, 'must be a public promoted constructor parameter'];
        yield 'narrow union' => [NarrowUnion::class, 'has a union type the wire cannot carry'];
        yield 'interface without converter' => [HoldsInterface::class, 'which has no converter'];
        yield 'hidden constructor' => [HiddenConstructor::class, 'needs a public constructor'];
        yield 'nested fragment' => [NestsFragment::class, 'only a top-level object may hold a fragment'];
        yield 'self reference' => [SelfReferencing::class, 'refers to itself'];
    }

    /** @param class-string $class */
    #[DataProvider('provideUnmappableClasses')]
    public function testClassTheWireCannotCarryFailsWhenDescribed(string $class, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        $this->mapper->shapes->resolveClassShape($class);
    }

    /** @param array<array-key, mixed> $tags */
    private static function createEverything(array $tags = ['room' => 'hall', 'nested' => ['a' => 1]]): Everything
    {
        return new Everything(
            name: 'hall',
            count: 3,
            ratio: 1.0,
            enabled: true,
            note: null,
            color: Color::Green,
            label: new Label('main'),
            labels: [new Label('a'), new Label('b')],
            numbers: [1, 2],
            tags: $tags,
            payload: ['list' => [1.5, null], 'flag' => false],
            seenAt: Instant::fromEpochMicroseconds(self::AT),
            lag: Duration::microseconds(1_250),
        );
    }

    private function sendThroughTheWire(Everything $sent): Everything
    {
        return $this->mapper->decodeObject(Everything::class, self::decodeJsonObject($this->mapper->encodeObject($sent)));
    }

    /** @return array<array-key, mixed> */
    private static function decodeJsonObject(string $json): array
    {
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
