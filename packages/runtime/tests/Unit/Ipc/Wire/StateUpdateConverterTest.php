<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc\Wire;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Ipc\Wire\EntityStateConverter;
use Stewart\Runtime\Ipc\Wire\EventContextConverter;
use Stewart\Runtime\Ipc\Wire\StateUpdateConverter;
use Stewart\Runtime\Json\EpochInstantConverter;
use Stewart\Runtime\Json\StringIdentifierConverter;
use Stewart\Support\Exception\JsonShapeError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(StateUpdateConverter::class)]
final class StateUpdateConverterTest extends TestCase
{
    use AssertsReason;

    #[DataProvider('provideBrokenUpdates')]
    public function testBrokenUpdateNamesItsPath(mixed $encoded, JsonShapeError $reason, string $key): void
    {
        $exception = $this->assertThrowsReason($reason, static fn() => self::createConverter()->decodeValue($encoded, 'updates.0'));

        self::assertSame($key, $exception->context['key'] ?? null);
    }

    /** @return iterable<string, array{mixed, JsonShapeError, string}> */
    public static function provideBrokenUpdates(): iterable
    {
        $valid = ['entity_id' => 'light.hall', 'to' => null, 'fired_at_us' => null, 'context' => null, 'origin' => 'live'];
        $state = ['entity_id' => 'light.hall', 'state' => 'on', 'attributes' => [], 'last_changed_at_us' => 'now', 'last_updated_at_us' => null, 'context' => null];

        yield 'not an object' => ['light.hall', JsonShapeError::WrongType, 'updates.0'];
        yield 'invalid entity id' => [['entity_id' => 'hall'] + $valid, JsonShapeError::UnexpectedValue, 'updates.0.entity_id'];
        yield 'unknown origin' => [['origin' => 'replay'] + $valid, JsonShapeError::UnexpectedValue, 'updates.0.origin'];
        yield 'origin missing' => [array_diff_key($valid, ['origin' => true]), JsonShapeError::UnexpectedValue, 'updates.0.origin'];
        yield 'fired at as text' => [['fired_at_us' => 'now'] + $valid, JsonShapeError::WrongType, 'updates.0.fired_at_us'];
        yield 'broken target state' => [['to' => $state] + $valid, JsonShapeError::WrongType, 'updates.0.to.last_changed_at_us'];
    }

    private static function createConverter(): StateUpdateConverter
    {
        $entityIds = StringIdentifierConverter::createForEntityIds();
        $instants = new EpochInstantConverter();
        $contexts = new EventContextConverter();

        return new StateUpdateConverter($entityIds, $instants, $contexts, new EntityStateConverter($entityIds, $instants, $contexts));
    }
}
