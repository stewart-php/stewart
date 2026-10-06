<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stewart\Contracts\Event\EventPayload;
use Stewart\Contracts\Exception\EventFireError;
use Stewart\Contracts\Exception\EventFireException;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(EventPayload::class)]
#[CoversClass(EventFireException::class)]
final class EventPayloadTest extends TestCase
{
    use AssertsReason;

    public function testPlainDataIsKeptWithNulls(): void
    {
        $data = ['button' => 'front', 'pressed' => true, 'meta' => ['count' => 2, 'note' => null]];

        $payload = new EventPayload('doorbell_pressed', $data);

        self::assertSame('doorbell_pressed', $payload->eventType);
        self::assertSame($data, $payload->data);
    }

    public function testEventTypeOfSixtyFourCharactersIsAccepted(): void
    {
        self::assertSame(64, \strlen(new EventPayload(str_repeat('a', 64))->eventType));
    }

    #[DataProvider('provideInvalidEventTypes')]
    public function testInvalidEventTypeIsRejected(string $eventType): void
    {
        $this->assertThrowsReason(EventFireError::TypeInvalid, static fn() => new EventPayload($eventType));
    }

    /** @return iterable<string, array{string}> */
    public static function provideInvalidEventTypes(): iterable
    {
        yield 'empty' => [''];
        yield 'longer than 64 characters' => [str_repeat('a', 65)];
    }

    public function testListDataIsRejected(): void
    {
        /** @phpstan-ignore argument.type (a list is the invalid input under test) */
        $this->assertThrowsReason(EventFireError::DataNotKeyed, static fn() => new EventPayload('doorbell_pressed', ['front', 'back']));
    }

    public function testObjectIsRejectedWithItsPath(): void
    {
        $e = $this->assertThrowsReason(EventFireError::DataInvalid, static fn() => new EventPayload('doorbell_pressed', ['meta' => [1, new stdClass()]]));

        self::assertSame('meta[1]', $e->context['path'] ?? null);
        self::assertSame('stdClass', $e->context['actualType'] ?? null);
    }

    public function testNonFiniteFloatIsRejected(): void
    {
        $e = $this->assertThrowsReason(EventFireError::DataInvalid, static fn() => new EventPayload('doorbell_pressed', ['ratio' => \NAN]));

        self::assertSame('float(NAN)', $e->context['actualType'] ?? null);
    }
}
