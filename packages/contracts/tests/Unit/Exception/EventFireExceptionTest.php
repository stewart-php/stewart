<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\EventFireError;
use Stewart\Contracts\Exception\EventFireException;

#[CoversClass(EventFireException::class)]
#[CoversClass(EventFireError::class)]
final class EventFireExceptionTest extends TestCase
{
    public function testRejectionNamesEventAndHaCode(): void
    {
        $failure = EventFireException::rejected('doorbell_pressed', 'Unauthorized', 'unauthorized');

        self::assertSame('Event doorbell_pressed was rejected by Home Assistant: Unauthorized (unauthorized)', $failure->getMessage());
        self::assertSame('unauthorized', $failure->context['errorCode'] ?? null);
    }

    public function testRejectionWithoutCodeAppendsNothing(): void
    {
        self::assertSame(
            'Event doorbell_pressed was rejected by Home Assistant: Unauthorized',
            EventFireException::rejected('doorbell_pressed', 'Unauthorized')->getMessage(),
        );
    }

    public function testWireRoundTripKeepsReasonMessageAndContext(): void
    {
        $original = EventFireException::overloaded('doorbell_pressed', 'busy');

        $rebuilt = EventFireException::fromWire($original->reason, $original->getMessage(), $original->context);

        self::assertSame(EventFireError::Overloaded, $rebuilt->reason);
        self::assertSame($original->getMessage(), $rebuilt->getMessage());
        self::assertSame($original->context, $rebuilt->context);
    }
}
