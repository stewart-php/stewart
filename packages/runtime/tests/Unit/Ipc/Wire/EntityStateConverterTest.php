<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc\Wire;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;
use Stewart\Runtime\Ipc\Wire\EntityStateConverter;
use Stewart\Runtime\Ipc\Wire\EventContextConverter;
use Stewart\Runtime\Json\EpochInstantConverter;
use Stewart\Runtime\Json\StringIdentifierConverter;
use Stewart\Support\Exception\JsonShapeError;
use Stewart\Support\Json\JsonEncoder;
use Stewart\Support\Json\JsonShape;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(EntityStateConverter::class)]
final class EntityStateConverterTest extends TestCase
{
    use AssertsReason;

    #[DataProvider('provideBrokenStates')]
    public function testBrokenStateNamesItsPath(mixed $encoded, JsonShapeError $reason, string $key): void
    {
        $exception = $this->assertThrowsReason($reason, static fn() => self::createConverter()->decodeValue($encoded, 'states.0'));

        self::assertSame($key, $exception->context['key'] ?? null);
    }

    /** @return iterable<string, array{mixed, JsonShapeError, string}> */
    public static function provideBrokenStates(): iterable
    {
        $valid = ['entity_id' => 'light.hall', 'state' => 'on', 'attributes' => [], 'last_changed_at_us' => null, 'last_updated_at_us' => null, 'context' => null];

        yield 'not an object' => ['on', JsonShapeError::WrongType, 'states.0'];
        yield 'invalid entity id' => [['entity_id' => 'hall'] + $valid, JsonShapeError::UnexpectedValue, 'states.0.entity_id'];
        yield 'state missing' => [array_diff_key($valid, ['state' => true]), JsonShapeError::WrongType, 'states.0.state'];
        yield 'attributes not an object' => [['attributes' => 'none'] + $valid, JsonShapeError::WrongType, 'states.0.attributes'];
        yield 'instant as text' => [['last_updated_at_us' => 'now'] + $valid, JsonShapeError::WrongType, 'states.0.last_updated_at_us'];
        yield 'broken context' => [['context' => ['id' => 1]] + $valid, JsonShapeError::WrongType, 'states.0.context.id'];
    }

    public function testAttributesAlwaysEncodeAsAnObject(): void
    {
        $converter = self::createConverter();

        self::assertStringContainsString('"attributes":{}', JsonEncoder::encodeToJson($converter->encodeValue(new EntityState(new EntityId('sensor.x'), '1'))));
        self::assertStringContainsString('"attributes":{"0":"a","1":"b"}', JsonEncoder::encodeToJson($converter->encodeValue(new EntityState(new EntityId('sensor.x'), '1', JsonShape::treatKeysAsStrings(['a', 'b'])))));
    }

    private static function createConverter(): EntityStateConverter
    {
        return new EntityStateConverter(StringIdentifierConverter::createForEntityIds(), new EpochInstantConverter(), new EventContextConverter());
    }
}
