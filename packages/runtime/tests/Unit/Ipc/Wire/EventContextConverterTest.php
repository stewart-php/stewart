<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc\Wire;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\State\EventContext;
use Stewart\Runtime\Ipc\Wire\EventContextConverter;
use Stewart\Support\Exception\JsonShapeError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(EventContextConverter::class)]
final class EventContextConverterTest extends TestCase
{
    use AssertsReason;

    #[DataProvider('provideBrokenContexts')]
    public function testBrokenContextNamesItsPath(mixed $encoded, string $key): void
    {
        $exception = $this->assertThrowsReason(JsonShapeError::WrongType, static fn() => new EventContextConverter()->decodeValue($encoded, 'context'));

        self::assertSame($key, $exception->context['key'] ?? null);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function provideBrokenContexts(): iterable
    {
        $valid = ['id' => 'ctx', 'parent_id' => null, 'user_id' => null];

        yield 'not an object' => ['ctx', 'context'];
        yield 'id missing' => [array_diff_key($valid, ['id' => true]), 'context.id'];
        yield 'parent id a number' => [['parent_id' => 7] + $valid, 'context.parent_id'];
        yield 'user id a list' => [['user_id' => ['user']] + $valid, 'context.user_id'];
    }

    public function testAbsentParentAndUserReadAsNull(): void
    {
        self::assertEquals(new EventContext('ctx'), new EventContextConverter()->decodeValue(['id' => 'ctx'], 'context'));
    }
}
